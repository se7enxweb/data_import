<?php

/**
 * @author pkamps
 *
 */
class ImportOperator
{

	public $source_handler;
	public $current_eZ_object;
	public $current_eZ_version;
	public $updated_array;
	public $do_publish = true;
	public $cli;

	/**
	 * 
	 */
	public function __construct()
	{
		$this->cli = eZCLI::instance();
		$this->cli->setUseStyles( true );
	}

	/**
	 * Main function 
	 */
	public function run()
	{
		$this->cli->output( $this->cli->stylize( 'cyan', 'Starting import with "' . $this->source_handler->handlerTitle . '" handler'."\n" ), false );

		$this->source_handler->readData();
		
		$force_exit = false;
		while( $this->source_handler->getNextRow() && !$force_exit )
		{
			$this->current_eZ_object = null;
			$this->current_eZ_version = null;
			
			$remoteID        = $this->source_handler->getDataRowId();
			$targetLanguage  = $this->source_handler->getTargetLanguage();
			
			$this->cli->output( 'Importing remote object ('.$this->cli->stylize( 'emphasize', $remoteID ).') ', false );

			$this->current_eZ_object = eZContentObject::fetchByRemoteID( $remoteID );

			if( !$this->current_eZ_object )
			{
				$this->create_eZ_node( $remoteID, $targetContentClass, $targetLanguage );
			}
			else
			{
				$this->update_eZ_node( $remoteID, $targetLanguage );
			}

			if( $this->current_eZ_object && $this->current_eZ_version )
			{
				$this->save_eZ_node();

				$post_save_success = $this->source_handler->post_save_handling( $this->current_eZ_object, $force_exit );

				if( $post_save_success )
				{
					$this->publish_eZ_node();

					$post_publish_success = $this->source_handler->post_publish_handling( $this->current_eZ_object, $force_exit );
					
					if( $post_publish_success )
					{
						$this->setNodesPriority();

						$this->cli->output( $this->cli->stylize( 'green', 'object ID ( '. $this->current_eZ_object->attribute( 'id' ) . ' )' . ".\n" ), false );
					}
					else
					{
						$this->cli->output( $this->cli->stylize( 'red', 'failed. Post handling after publish not successful.'."\n" ), false );
					}
				}
				else
				{
					$this->cli->output( $this->cli->stylize( 'red', 'failed. Post handling after save not successful.'."\n" ), false );
				}
				
				# Clear content object from $GLOBALS - to prevent OOM (not mana)
				unset( $GLOBALS[ 'eZContentObjectContentObjectCache' ] );
				unset( $GLOBALS[ 'eZContentObjectDataMapCache' ] );
				unset( $GLOBALS[ 'eZContentObjectVersionCache' ] );	
			}
			else
			{
				$this->cli->output( '..'.$this->cli->stylize( 'gray', 'skipped.'."\n" ), false );
			}
		}

		$this->cli->output( $this->cli->stylize( 'cyan', 'Finished.' . "\n" ), false );
	}

	/**
	 * Create new eZ Publish version for existing eZ Object
	 * 
	 * @param int $remoteID
	 * @param string $targetLanguage
	 * @return boolean
	 */
	protected function update_eZ_node( $remoteID, $targetLanguage = null )
	{
		//TODO: consider to also update the eZcontentobject attribute values like publish_date, owner, etc
		$this->cli->output( 'updating ' , false );
		
		// Create new eZ Publish version for existing eZ Object
		// TODO - check parent nod id consitence - and create 2nd location if needed
		$this->do_publish = true;

		$this->current_eZ_version = $this->current_eZ_object->createNewVersion( false, true, $targetLanguage );
		
		return true;
	}
	
	
	/**
	 * Create new eZ publish object in Database
	 * 
	 * @param unknown_type $remoteID
	 * @param unknown_type $row
	 * @param unknown_type $targetContentClass
	 * @param unknown_type $targetLanguage
	 * @return boolean
	 */
	protected function create_eZ_node( $remoteID, $targetContentClass, $targetLanguage = null )
	{
		$return = false;
		
		$targetContentClass = $this->source_handler->getTargetContentClass();
		
		$eZObjectAttributes = array_merge(
				$this->source_handler->getEzObjAttributes(),
				array('remote_id' => $remoteID )
		);

		$this->cli->output( 'creating (' . $this->cli->stylize( 'emphasize', $targetContentClass ) . ') ' , false );
		
		$eZ_object = MugoHelpers::createEzObject(
				$eZObjectAttributes,
				$targetContentClass,
				$this->source_handler->getParentNodeId()
		);
		
		if( $eZ_object )
		{
			$this->current_eZ_object  = $eZ_object;
			$this->current_eZ_version = $eZ_object->currentVersion();
							
			$this->do_publish = true;
			$return = true;	
		}
		else
		{
			$this->cli->output( $this->cli->stylize( 'red', 'Could not create eZContentObject. (Wrong content class identifer?)' ), false );
		}

		return $return;
	}

	protected function save_eZ_node()
	{
		$dataMap  = $this->current_eZ_version->attribute( 'data_map' );

		while( $this->source_handler->getNextField() )
		{
			$contentObjectAttribute = $dataMap[ $this->source_handler->geteZAttributeIdentifierFromField() ];
			
			if( $contentObjectAttribute )
			{
				$this->save_eZ_attribute( $contentObjectAttribute );
			}
			else
			{
				$this->cli->output( $this->cli->stylize( 'red', 'eZ Attribute ('.$this->source_handler->geteZAttributeIdentifierFromField().') does not exist - skipped.' ), false );
			}
		}
		
		$this->current_eZ_object->store();
	}

	/**
	 * @return boolean
	 */
	protected function publish_eZ_node()
	{
		if( $this->do_publish )
		{
			return eZOperationHandler::execute(
			                                   'content',
			                                   'publish',
			                                   array(
			                                         'object_id' => $this->current_eZ_object->attribute( 'id' ),
			                                         'version'   => $this->current_eZ_version->attribute( 'version' ),
			                                        )
			                                  );
		}
		else
		{
			return true;
		}
	}

	protected function save_eZ_attribute( $contentObjectAttribute )
	{
		$value = '';

		switch( $contentObjectAttribute->attribute( 'data_type_string' ) )
		{
			case 'ezobjectrelation':
			{
				// Remove any exisiting value first from ezobjectrelation
				/*
				eZContentObject::removeContentObjectRelation( $contentObjectAttribute->attribute('data_int'),
				                                              $this->current_eZ_object->attribute('current_version'),
				                                              $this->current_eZ_object->attribute('id'),
				                                              $contentObjectAttribute->attribute('contentclassattribute_id')
				                                              );
				*/
				$contentObjectAttribute->setAttribute( 'data_int', 0 );
				$contentObjectAttribute->store();

				$value = $this->source_handler->getValueFromField( $contentObjectAttribute );
			}
			break;
			
			case 'ezobjectrelationlist':
			{
				// Remove any exisiting value first from ezobjectrelationlist
				
				$content = $contentObjectAttribute->content();
                $relationList =& $content['relation_list'];
                $newRelationList = array();
                for ( $i = 0; $i < count( $relationList ); ++$i )
                {
                    $relationItem = $relationList[$i];
                    eZObjectRelationListType::removeRelationObject( $contentObjectAttribute, $relationItem );
                }
                $content['relation_list'] =& $newRelationList;
                $contentObjectAttribute->setContent( $content );
                $contentObjectAttribute->store();
                
                $value = $this->source_handler->getValueFromField( $contentObjectAttribute );
			}
			break;

			default:
				$value = $this->source_handler->getValueFromField( $contentObjectAttribute );
		}
		
		// fromString returns false - even when it is successfull
		// create a bug report for that
		$contentObjectAttribute->fromString( $value );
		$contentObjectAttribute->store();
	}
			
	protected function setNodesPriority()
	{	
		$node_priority = $this->source_handler->getPriorityForNode();
		
		if($node_priority !== false)
		{
			$parent_node_id = $this->source_handler->getParentNodeId();
			$assigned_nodes = $this->current_eZ_object->attribute('assigned_nodes');	
			$db = eZDB::instance();

			foreach($assigned_nodes as $assigned_node)
			{
				$parent = $assigned_node->attribute('parent');
				if( $parent && $parent->attribute('node_id') == $parent_node_id)
				{
					$db->begin();
					$nodeID = $assigned_node->attribute('node_id');
					$db->query( "UPDATE ezcontentobject_tree SET priority=$node_priority WHERE node_id=$nodeID" );
					$assigned_node->updateAndStoreModified();
					$db->commit();
				}
			}
		}
	}
}

?>