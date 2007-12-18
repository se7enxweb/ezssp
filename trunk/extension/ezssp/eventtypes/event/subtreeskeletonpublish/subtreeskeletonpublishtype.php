<?php
//
// ## BEGIN COPYRIGHT, LICENSE AND WARRANTY NOTICE ##
// SOFTWARE NAME: eZ publish Subtree Skeleton Publisher extension
// SOFTWARE RELEASE: 0.x
// COPYRIGHT NOTICE: Copyright (C) 2007 Kristof Coomans <http://blog.kristofcoomans.be>
// SOFTWARE LICENSE: GNU General Public License v2.0
// NOTICE: >
//   This program is free software; you can redistribute it and/or
//   modify it under the terms of version 2.0  of the GNU General
//   Public License as published by the Free Software Foundation.
//
//   This program is distributed in the hope that it will be useful,
//   but WITHOUT ANY WARRANTY; without even the implied warranty of
//   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//   GNU General Public License for more details.
//
//   You should have received a copy of version 2.0 of the GNU General
//   Public License along with this program; if not, write to the Free
//   Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
//   MA 02110-1301, USA.
//
//
// ## END COPYRIGHT, LICENSE AND WARRANTY NOTICE ##
//

include_once( 'kernel/classes/ezworkflowtype.php' );
include_once( 'kernel/classes/ezcontentobject.php' );

class SubtreeSkeletonPublishType extends eZWorkflowEventType
{
    var $oldNodeIDToNewObjectIDMap = array();
    var $ownerID;

    function SubtreeSkeletonPublishType()
    {
        $this->eZWorkflowEventType( 'subtreeskeletonpublish', ezi18n( 'extension/ezssp', 'Subtree Skeleton Publisher' ) );
        // limit workflows which use this event to be used only on the post-publish trigger
        $this->setTriggerTypes( array( 'content' => array( 'publish' => array( 'after' ) ) ) );

        $this->oldNodeIDToNewObjectIDMap = array();
    }

    function attributeDecoder( $event, $attr )
    {
        $retValue = null;
        switch( $attr )
        {
            case 'skeleton_node_id':
            {
                $retValue = $event->attribute( 'data_int1' );
            } break;

            case 'skeleton_user_groups':
            {
                $retValue = $this->unserializeUserGroupsConfig( $event );
            } break;

            case 'role_list':
            {
                include_once( 'kernel/classes/ezrole.php' );
                $retValue = eZRole::fetchList();
            } break;

            default:
            {
                eZDebug::writeNotice( 'unknown attribute: ' . $attr, 'SubtreeSkeletonPublishType' );
            }
        }
        return $retValue;
    }

    function typeFunctionalAttributes()
    {
        return array( 'skeleton_node_id', 'skeleton_user_groups', 'role_list' );
    }

    function unserializeUserGroupsConfig( $event )
    {
        $retValue = array();
        $xmlString = $event->attribute( 'data_text1' );
        if ( $xmlString =='' )
        {
            return $retValue;
        }

        include_once( 'lib/ezxml/classes/ezxml.php' );
        $xml = new eZXML();
        $dom = $xml->domTree( $xmlString );
        $root = $dom->root();
        $groups = $root->elementsByName( 'group' );

        foreach ( $groups as $group )
        {
            $nodeID = $group->get_attribute( 'node_id' );
            $addOwner = ( $group->get_attribute( 'add_owner' ) !== false );
            $roles = $group->elementsByName( 'role' );

            $roleList = array();
            foreach ( $roles as $role )
            {
                $roleList[] = $role->get_attribute( 'role_id' );
            }

            $retValue[$nodeID] = array( 'roles' => $roleList, 'add_owner' => $addOwner );
        }

        return $retValue;
    }

    function serializeUserGroupsConfig( $userGroups )
    {
        include_once( 'lib/ezxml/classes/ezxml.php' );
        $dom = new eZDOMDocument();
        $skeleton = $dom->createElement( 'skeleton' );
        $dom->setRoot( $skeleton );

        foreach ( $userGroups as $nodeID => $groupConfig )
        {
            unset( $groupNode );
            $groupNode = $dom->createElement( 'group' );
            $groupNode->setAttribute( 'node_id', $nodeID );
            $skeleton->appendChild( $groupNode );

            if ( $groupConfig['add_owner'] == true )
            {
                $groupNode->setAttribute( 'add_owner', 'true' );
            }

            if ( array_key_exists( 'roles', $groupConfig ) )
            {
                foreach ( $groupConfig['roles'] as $roleID )
                {
                    unset( $roleNode );
                    $roleNode = $dom->createElement( 'role' );
                    $roleNode->setAttribute( 'role_id', $roleID );
                    $groupNode->appendChild( $roleNode );
                }
            }
        }

        $xmlString = $dom->toString();
        eZDebug::writeDebug( $xmlString, 'serializeUserGroupsConfig' );
        return $xmlString;
    }

    function fetchHTTPInput( $http, $base, $event )
    {
        $userGroups = $this->attributeDecoder( $event, 'skeleton_user_groups' );

        // this condition can be removed when this issue if fixed: http://issues.ez.no/10685
        if ( count( $_POST ) > 0 )
        {
            $userGroups = $this->attributeDecoder( $event, 'skeleton_user_groups' );

            $userGroupRoles = array();
            $rolesPostVarName = 'UserGroupRoleList_' . $event->attribute( 'id' );
            if ( $http->hasPostVariable( $rolesPostVarName ) )
            {
                $userGroupRoles = $http->postVariable( $rolesPostVarName );
            }

            $addOwnerGroups = array();
            $addOwnerPostVarName = 'UserGroupAddOwner_' . $event->attribute( 'id' );
            if ( $http->hasPostVariable( $addOwnerPostVarName ) && is_array( $http->postVariable( $addOwnerPostVarName ) ) )
            {
                $addOwnerGroups = $http->postVariable( $addOwnerPostVarName );
            }

            foreach ( $userGroups as $groupID => $groupConfig )
            {
                if ( array_key_exists( $groupID, $userGroupRoles ) )
                {
                    $userGroups[$groupID]['roles'] = $userGroupRoles[$groupID];
                }
                else
                {
                    $userGroups[$groupID]['roles'] = array();
                }

                $userGroups[$groupID]['add_owner'] = in_array( $groupID, $addOwnerGroups );
            }

            $serializedUserGroupsConfig = $this->serializeUserGroupsConfig( $userGroups );
            eZDebug::writeDebug( $serializedUserGroupsConfig, 'fetchHTTPInput' );
            $event->setAttribute( 'data_text1', $serializedUserGroupsConfig );
        }
    }

    /*!
     \reimp
    */
    function customWorkflowEventHTTPAction( $http, $action, $workflowEvent )
    {
        $eventID = $workflowEvent->attribute( 'id' );
        $module = $GLOBALS['eZRequestedModule'];

        switch ( $action )
        {
            case 'SelectSkeleton':
            {
                include_once( 'kernel/classes/ezcontentbrowse.php' );
                eZContentBrowse::browse( array( 'action_name' => 'SelectSkeleton',
                                                'browse_custom_action' => array( 'name' => 'CustomActionButton[' . $eventID . '_SkeletonSelected]',
                                                                                 'value' => $eventID ),
                                                'from_page' => '/workflow/edit/' . $workflowEvent->attribute( 'workflow_id' ),
                                                'ignore_nodes_select' => $this->attributeDecoder( $workflowEvent, 'skeleton_node_id' )
                                               ),
                                         $module );
            } break;

            case 'SkeletonSelected':
            {
                include_once( 'kernel/classes/ezcontentbrowse.php' );
                $nodeList = eZContentBrowse::result( 'SelectSkeleton' );
                if ( $nodeList )
                {
                    $workflowEvent->setAttribute( 'data_int1', $nodeList[0] );
                }
            } break;

            case 'AddSkeletonUserGroups':
            {
                include_once( 'kernel/classes/ezcontentbrowse.php' );
                eZContentBrowse::browse( array( 'action_name' => 'AddSkeletonUserGroups',
                                                'browse_custom_action' => array( 'name' => 'CustomActionButton[' . $eventID . '_SkeletonUserGroupsAdded]',
                                                                                 'value' => $eventID ),
                                                'start_node' => $this->attributeDecoder( $workflowEvent, 'skeleton_node_id' ),
                                                'from_page' => '/workflow/edit/' . $workflowEvent->attribute( 'workflow_id' ),
                                                'ignore_nodes_select' => array_keys( $this->attributeDecoder( $workflowEvent, 'skeleton_user_groups' ) )
                                               ),
                                         $module );
            } break;

            case 'SkeletonUserGroupsAdded':
            {
                include_once( 'kernel/classes/ezcontentbrowse.php' );
                $nodeList = eZContentBrowse::result( 'AddSkeletonUserGroups' );
                if ( $nodeList )
                {
                    $this->addUserGroups( $workflowEvent, $nodeList );
                }
            } break;

            case 'RemoveSkeletonUserGroups':
            {
                $removeVarName = 'DeleteUserGroupIDList_' . $eventID;
                if ( $http->hasPostVariable( $removeVarName ) )
                {
                    $removeList = $http->postVariable( $removeVarName );
                    $this->removeUserGroups( $workflowEvent, $removeList );
                }
            } break;

            default:
            {
                eZDebug::writeNotice( 'unknown custom action: ' . $action, 'SubtreeSkeletonPublishType' );
            }
        }
    }

    /*!
     \brief Adds user groups to the list
    */
    function addUserGroups( $workflowEvent, $nodeList )
    {
        $userGroups = $this->attributeDecoder( $workflowEvent, 'skeleton_user_groups' );

        foreach ( $nodeList as $nodeID )
        {
            if ( !array_key_exists( $nodeID, $userGroups ) )
            {
                $userGroups[$nodeID] = array( 'roles' => array(), 'add_owner' => false );
            }
        }

        $serializedUserGroupsConfig = $this->serializeUserGroupsConfig( $userGroups );
        eZDebug::writeDebug( $serializedUserGroupsConfig, 'addUserGroups' );
        $workflowEvent->setAttribute( 'data_text1', $serializedUserGroupsConfig );
    }

    function removeUserGroups( $workflowEvent, $nodeList )
    {
        $userGroups = $this->attributeDecoder( $workflowEvent, 'skeleton_user_groups' );

        foreach ( $nodeList as $nodeID )
        {
            if ( array_key_exists( $nodeID, $userGroups ) )
            {
                unset( $userGroups[$nodeID] );
            }
        }

        $serializedUserGroupsConfig = $this->serializeUserGroupsConfig( $userGroups );
        eZDebug::writeDebug( $serializedUserGroupsConfig, 'removeUserGroups' );
        $workflowEvent->setAttribute( 'data_text1', $serializedUserGroupsConfig );
    }

    function execute( $process, $event )
    {
        // global variable to prevent endless recursive workflows with this event
        $recursionProtect = 'SubTreeSkelectonPublishType_recursionprotect_' . $event->attribute( 'id' );
        if ( array_key_exists( $recursionProtect, $GLOBALS ) )
        {
            return eZWorkflowType::STATUS_ACCEPTED;
        }

        $parameters = $process->attribute( 'parameter_list' );
        $object = eZContentObject::fetch( $parameters['object_id'] );

        // if the object is not published for the first time, then we don't do anything
        if ( $object->attribute( 'modified' ) != $object->attribute( 'published' ) )
        {
            return eZWorkflowType::STATUS_ACCEPTED;
        }

        // put the following block in comments for easy debugging

        // defer to cron, this is safer because we are going to create some other objects as well
        include_once( 'lib/ezutils/classes/ezsys.php' );
        if ( eZSys::isShellExecution() == false )
        {
            return eZWorkflowType::STATUS_DEFERRED_TO_CRON_REPEAT;
        }

        if ( !array_key_exists( $recursionProtect, $GLOBALS ) )
        {
            $GLOBALS[$recursionProtect] = true;
        }

        $this->copySkeleton( $object, $event );
        $this->addOwnerLocation( $object, $event );
        $this->assignRoles( $object, $event );

        unset( $GLOBALS[$recursionProtect] );
        return eZWorkflowType::STATUS_ACCEPTED;
    }

    function addOwnerLocation( $object, $event )
    {
        $userGroups = $this->attributeDecoder( $event, 'skeleton_user_groups' );
        $userID = $object->attribute( 'owner_id' );

        foreach ( $userGroups as $groupNodeID => $groupConfig )
        {
            if ( $groupConfig['add_owner'] == true )
            {
                if ( !array_key_exists( $groupNodeID, $this->oldNodeIDToNewObjectIDMap ) )
                {
                    // show debug warning
                    continue;
                }

                $newGroupID = $this->oldNodeIDToNewObjectIDMap[$groupNodeID];

                include_once( 'lib/ezutils/classes/ezoperationhandler.php' );
                $operationResult = eZOperationHandler::execute( 'membership', 'register', array( 'group_id' => $newGroupID, 'user_id' => $userID ) );
            }
        }
    }

    function assignRoles( $object, $event )
    {
        include_once( 'lib/ezdb/classes/ezdb.php' );
        include_once( 'lib/ezutils/classes/ezini.php' );
        include_once( 'kernel/classes/ezrole.php' );

        $madeChanges = array();

        include_once( 'lib/ezdb/classes/ezdb.php' );
        $db = eZDB::instance();
        $db->begin();

        $userGroups = $this->attributeDecoder( $event, 'skeleton_user_groups' );
        foreach ( $userGroups as $groupNodeID => $groupConfig )
        {
            // use the node id of the copied node
            if ( !array_key_exists( $groupNodeID, $this->oldNodeIDToNewObjectIDMap ) )
            {
                // show debug warning
                continue;
            }

            $newGroupID = $this->oldNodeIDToNewObjectIDMap[$groupNodeID];

            foreach ( $groupConfig['roles'] as $roleID )
            {
                include_once( 'kernel/classes/ezrole.php' );
                $role = eZRole::fetch( $roleID );

                if ( !is_object( $role ) )
                {
                    // show debug warning
                    continue;
                }

                $projectNode = $object->attribute( 'main_node' );
                $pathString = $projectNode->attribute( 'path_string' );

                $query = "INSERT INTO ezuser_role ( role_id, contentobject_id, limit_identifier, limit_value ) VALUES ( '$roleID', '$newGroupID', 'Subtree', '$pathString' )";
                $db->query( $query );
            }
        }

        $db->commit();

        if ( in_array( true, $madeChanges ) )
        {
            eZRole::expireCache();

            include_once( 'kernel/classes/ezcontentcachemanager.php' );
            eZContentCacheManager::clearAllContentCache();

            include_once( 'kernel/classes/datatypes/ezuser/ezuser.php' );
            eZUser::cleanupCache();
        }
    }

    /*
        PART: SKELETON
    */
    function copySkeleton( $object, $event )
    {
        $projectNode = $object->attribute( 'main_node' );
        $this->ownerID = $object->attribute( 'owner_id' );

        $skeletonNodeID = $this->attributeDecoder( $event, 'skeleton_node_id' );
        $skeletonNode = eZContentObjectTreeNode::fetch( $skeletonNodeID );

        $this->copyChildrenRecursive( $skeletonNode, $projectNode );
    }

     /*
        Maybe we need to use this first parameter, to ignore policies
        But I don't think it does any harm that each user can read the skeleton

        array( 'Limitation' => array() )

        maybe it's useful to not read every node because of permissions of the project creator
        then we can insert customer groups and other stuff too in the skeleton
    */
    function copyChildrenRecursive( $sourceParentNode, $targetParentNode )
    {
        $db = eZDB::instance();
        $db->begin();

        $sourceParentNodeID = $sourceParentNode->attribute( 'node_id' );

        $timeSortFields = array( 'published', 'modified', 'modified_subnode' );
        $sortOrder = $sourceParentNode->attribute( 'sort_order' );
        $sortField = $sourceParentNode->attribute( 'sort_field' );
        $delay = false;

        if ( in_array( eZContentObjectTreeNode::sortFieldName( $sortField ), $timeSortFields ) )
        {
            // bitwise NOT
            $sortOrder = ~ $sortOrder;
            $delay = true;
        }

        $sortArray = eZContentObjectTreeNode::sortArrayBySortFieldAndSortOrder( $sourceParentNode->attribute( 'sort_field' ), $sortOrder );

        $subTreeParams = array(
            'Depth' => 1,
            'DepthOperator' => 'eq',
            'Limitation' => array(),
            'SortBy' => $sortArray
            );

        $sourceNodeList = eZContentObjectTreeNode::subTreeByNodeID( $subTreeParams, $sourceParentNodeID );

        foreach ( $sourceNodeList as $sourceNode )
        {
            eZDebug::writeDebug( $sourceNode->attribute( 'name' ) );
            $newNode = $this->copyNode( $sourceNode, $targetParentNode, $sourceParentNode );
            $this->oldNodeIDToNewObjectIDMap[$sourceNode->attribute( 'node_id' )] = $newNode->attribute( 'contentobject_id' );
            $sourceObj = $sourceParentNode->object();
            $contentClass = $sourceObj->attribute( 'content_class' );
            if ( $contentClass->attribute( 'is_container' ) )
            {
                $this->copyChildrenRecursive( $sourceNode, $newNode );
            }

            if ( $delay )
            {
                eZDebug::writeDebug( 'found timed-sorted subtree, sleeping 2 seconds' );
                sleep( 2 );
            }
        }

        $db->commit();
    }

    function copyNode( $sourceNode, $targetParentNode, $sourceParentNode )
    {
        $sourceParentObject = $sourceParentNode->attribute( 'object' );
        $tagetParentObject = $targetParentNode->attribute( 'object' );
        $object = $sourceNode->attribute( 'object' );

        $sectionID = $tagetParentObject->attribute( 'section_id' );
        if ( $object->attribute( 'section_id' ) != $sourceParentObject->attribute( 'section_id' ) )
        {
            $sectionID = $object->attribute( 'section_id' );
        }

        //eZDebug::writeDebug( 'section id: ' . $sectionID );

        $newObject = $object->copy( false );
        $newObject->setAttribute( 'section_id', $sectionID );
        $newObject->setAttribute( 'owner_id', $this->ownerID );
        $newObject->store();
        $newParentNodeID = $targetParentNode->attribute( 'node_id' );

        $curVersion        = $newObject->attribute( 'current_version' );
        $curVersionObject  = $newObject->attribute( 'current' );
        $curVersionObject->setAttribute( 'creator_id', $this->ownerID );
        $curVersionObject->store();
        $newObjAssignments = $curVersionObject->attribute( 'node_assignments' );
        unset( $curVersionObject );

        // remove old node assignments
        foreach( $newObjAssignments as $assignment )
        {
            $assignment->remove();
        }

        // and create a new one
        $nodeAssignment = eZNodeAssignment::create( array(
                                                         'contentobject_id' => $newObject->attribute( 'id' ),
                                                         'contentobject_version' => $curVersion,
                                                         'parent_node' => $newParentNodeID,
                                                         'is_main' => 1,
                                                         'sort_field' => $sourceNode->attribute( 'sort_field' ),
                                                         'sort_order' => $sourceNode->attribute( 'sort_order' )
                                                         ) );
        $nodeAssignment->store();

        include_once( 'lib/ezutils/classes/ezoperationhandler.php' );
        $result = eZOperationHandler::execute( 'content', 'publish',
            array( 'object_id' => $newObject->attribute( 'id' ),
                   'version'   => $curVersion ) );

        // Update "priority" and "is_invisible" attribute for the newly created node.
        $newNode = $newObject->attribute( 'main_node' );
        $newNode->setAttribute( 'priority', $sourceNode->attribute( 'priority' ) );
        $newNode->store();
        eZContentObjectTreeNode::updateNodeVisibility( $newNode, $targetParentNode );

        return $newNode;
    }
}

eZWorkflowEventType::registerEventType( 'subtreeskeletonpublish', 'SubtreeSkeletonPublishType' );

?>
