<?php
/**
 * Babel
 *
 * Copyright 2010 by Jakob Class <jakob.class@class-zec.de>
 *
 * This file is part of Babel.
 *
 * Babel is free software; you can redistribute it and/or modify it under the
 * terms of the GNU General Public License as published by the Free Software
 * Foundation; either version 2 of the License, or (at your option) any later
 * version.
 *
 * Babel is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR
 * A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * Babel; if not, write to the Free Software Foundation, Inc., 59 Temple Place,
 * Suite 330, Boston, MA 02111-1307 USA
 *
 * @package babel
 */
/**
 * This file is the main class file for Babel.
 * 
 * Based on ideas of Sylvain Aerni <enzyms@gmail.com>
 * 
 * @author Jakob Class <jakob.class@class-zec.de>
 *
 * @package babel
 */
class Babel {

    /**
     * @access protected
     * @var array A collection of preprocessed chunk values.
     */
    protected $chunks = array();
    /**
     * @access public
     * @var modX A reference to the modX object.
     */
    public $modx = null;
    /**
     * @access public
     * @var array A collection of properties to adjust Babel behaviour.
     */
    public $config = array();    
    /**
     * @access public
     * @var    modTemplateVar A reference to the babel TV which is used to store linked resources.
     *         The linked resources are stored using this syntax: [contextKey1]:[resourceId1];[contextKey2]:[resourceId2]
     *         Example: web:1;de:4;es:7;fr:10
     */
    public $babelTv = null;
    /** @var array Stores data between the OnResourceBeforeSort and OnResourceSort events. */
    public $sort_resource_data_storage;

    /**
     * The Babel Constructor.
     *
     * This method is used to create a new Babel object.
     *
     * @param modX &$modx A reference to the modX object.
     * @param array $config A collection of properties that modify Babel
     * behaviour.
     * @return Babel A unique Babel instance.
     */
    function __construct(modX &$modx,array $config = array()) {
        $this->modx =& $modx;
        
        $corePath = $this->modx->getOption('babel.core_path',null,$modx->getOption('core_path').'components/babel/');
        $assetsUrl = $this->modx->getOption('babel.assets_url',null,$modx->getOption('assets_url').'components/babel/');
        
        $contextKeysOption = $this->modx->getOption('babel.contextKeys',$config,'');
        $contextKeyToGroup = $this->decodeContextKeySetting($contextKeysOption);
        $syncTvsOption = $this->modx->getOption('babel.syncTvs',$config,'');
        $syncTvs = array();
        if(!empty($syncTvsOption)) {
            $syncTvs = explode(',', $syncTvsOption);
            $syncTvs = array_map('intval', $syncTvs);
        }
        $babelTvName = $this->modx->getOption('babel.babelTvName',$config,'babelLanguageLinks');

        $this->config = array_merge(array(
            'corePath' => $corePath,
            'chunksPath' => $corePath.'elements/chunks/',
            'chunkSuffix' => '.chunk.tpl',
               'cssUrl' => $assetsUrl.'css/',
            'jsUrl' => $assetsUrl.'js/',
            'contextKeyToGroup' => $contextKeyToGroup,
            'syncTvs' => $syncTvs,
            'babelTvName' => $babelTvName,
            'resource_no_sync_field_types' => array('string','password','array','json'),
            'resource_sync_field_types' => array('datetime','timestamp','date','time','boolean','integer','int','float'),
        ),$config);

        /* load babel lexicon */
        if ($this->modx->lexicon) {
            $this->modx->lexicon->load('babel:default');
        }

        /* load babel TV */
        
        $this->babelTv = $modx->getObject('modTemplateVar',array('name' => $babelTvName));
        if(!$this->babelTv) {
            $this->modx->log(modX::LOG_LEVEL_WARN, 'Could not load babel TV: '.$babelTvName.' will try to create it...');
            $fields = array(
                'name' => $babelTvName,
                'type' => 'hidden',
                'default_text' => '',
                'caption' => $this->modx->lexicon('babel.tv_caption'),
                'description'=>$this->modx->lexicon('babel.tv_description'));
            $this->babelTv = $modx->newObject('modTemplateVar', $fields);
            if($this->babelTv->save()) {
                $this->modx->log(modX::LOG_LEVEL_INFO, 'Created babel TV: '.$babelTvName);
            } else {
                $this->modx->log(modX::LOG_LEVEL_ERROR, 'Could not create babel TV: '.$babelTvName);
            }
        }
    }

    /**
     * @return array Array of modResource field names.
     */
    public function getResourceFieldsToSync( /* $resourceId = null */ ) {
        /* go through each linked resource and update proper fields */
//        $resource_fields = $this->modx->getFields('modResource');
        $sync_field_types = $this->config['resource_sync_field_types'];
        $no_sync_field_types = $this->config['resource_no_sync_field_types'];
        $fieldMeta = $this->modx->getFieldMeta('modResource');
        $fields_to_sync = array();
        foreach($fieldMeta as $field_name => $field_data) {
            // always skip primary key
            if (in_array($field_name, array('id','context_key'))) {
                continue;
            }
            $field_type = $field_data['phptype'];
            if (in_array($field_type,$sync_field_types)) {
                $fields_to_sync[] = $field_name;
            } else if (!in_array($field_type,$no_sync_field_types)) {
                $this->modx->log(modX::LOG_LEVEL_ERROR, 'Babel: Unknown field type '.$field_type.' for field '.$field_name);
            }
        }
        return $fields_to_sync;
    }

    /**
     * Synchronizes the TVs of the specified resource with its translated resources.
     *
     * @param int $source_resource_id id of resource.
     * @param bool $sync_fields Whether to sync ANY resource fields
     * @param bool $sync_tvs Whether to sync ANY template variables
     * @return array An associative array of resource ids to modResource objects of linked resources that CHANGED.
     */
    public function synchronizeTvs($source_resource_id, $sync_fields=true, $sync_tvs=true) {
        $linkedResourceIds = $this->getLinkedResources($source_resource_id);
        /* check if Babel TV has been initiated for the specified resource */
        if(empty($linkedResourceIds)) {
            $linkedResourceIds = $this->initBabelTvById($source_resource_id);
        }
        /** @var array $linkedResources An array of resource ids to resource objects linked to the current resource. */
        $linkedResources = array();
        foreach($linkedResourceIds as $index => $linkedResourceId){
            /* don't synchronize resource with itself */
            if($source_resource_id == $linkedResourceId) {
                unset($linkedResourceIds[$index]);
                continue;
            }
            /* load the modResource object as the value to the resource id key */
            $resource = $this->modx->getObject('modResource',$linkedResourceId);
            if (!($resource instanceof modResource)) {
                $this->modx->log(modX::LOG_LEVEL_ERROR, 'Babel: error loading linked resource '.$linkedResourceId);
                continue;
            }
            $linkedResources[$linkedResourceId] = $resource;
        }
        /** @var $source_resource modResource */
        $source_resource = $this->modx->getObject('modResource',$source_resource_id);
        if (!$source_resource instanceof modResource) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, 'Babel: error loading source resource '.$source_resource_id);
            return array();
        }
        // update the resources by reference in $linkedResources and get a list of changed ids
        $changed_resource_ids_fields = $sync_fields ? $this->_synchronizeResourceFields($source_resource, $linkedResources) : array();
        $changed_resource_ids_tvs = $sync_tvs ? $this->_synchronizeTvs($source_resource, $linkedResources) : array();
        /** @var array $changed_resource_ids An array of resource ids that were changed in the $linkedResources array. */
        $changed_resource_ids = array_unique(array_merge($changed_resource_ids_fields, $changed_resource_ids_tvs));
        /** @var array $synced_resources The associative array of changed ids to changed modResource objects. */
        $synced_resources = array();
        foreach ($changed_resource_ids as $changed_resource_id) {
            /** @var $changed_resource modResource */
            $changed_resource = $linkedResources[$changed_resource_id];
            $changed_resource->save();
            $synced_resources[$changed_resource_id] = $changed_resource;
        }
        if ($synced_resources) {
            $this->modx->cacheManager->refresh();
        }
        return $synced_resources;
    }

    /**
     * Synchronize template variable values.
     *
     * @param modResource $source_resource The changed resource to use as the source of info.
     * @param array $target_resources A reference to an array of modResource objects to update based on the source resource.
     * @return array Array of resource ids that were changed in the $linkedResources array
     */
    protected function _synchronizeTvs(modResource $source_resource, array &$target_resources) {
        $changed_resource_ids = array();
        $syncTvs = $this->modx->getOption('syncTvs', $this->config, array());
        foreach ($syncTvs as $tvId) {
            /* go through each TV which should be synchronized */
            /** @var $tv modTemplateVar */
            $tv = $this->modx->getObject('modTemplateVar', $tvId);
            if (!$tv) {
                continue;
            }
            $newTvValue = $tv->getValue($source_resource->get('id'));
            foreach ($target_resources as $target_resource) {
                /** @var $target_resource modResource */
                $oldTvValue = $tv->getValue($target_resource->get('id'));
                if ($newTvValue != $oldTvValue) {
                    $target_resource->setTVValue($tvId, $newTvValue);
                    $changed_resource_ids[] = $target_resource->get('id');
                }
            }
        }
        return $changed_resource_ids;
    }

    /**
     * Synchronize certain linked resource field values.
     *
     * @param modResource $source_resource The changed resource to use as the source of info.
     * @param array $target_resources A reference to an array of modResource objects to update based on the source resource.
     * @return array Array of resource ids that were changed in the $linkedResources array
     */
    protected function _synchronizeResourceFields(modResource $source_resource, array &$target_resources) {
        $this->modx->log(modX::LOG_LEVEL_INFO, "Babel: Updating resources {".implode(',',array_keys($target_resources))."} from source resource {$source_resource->get('id')}");
        $changed_resource_ids = array();
        $fields_to_sync = $this->getResourceFieldsToSync();
        foreach ($fields_to_sync as $field_name) {
            $newFieldValue = $sourceValue = $source_resource->get($field_name);
            foreach ($target_resources as $target_resource) {
                /** @var $target_resource modResource */
                // also sync parent to matching resource in the linked context - do not remove without replacing!
                if ($field_name == 'parent') {
                    $newFieldValue = (int) $this->getLinkedParentId($source_resource->get('parent'),$target_resource->get('context_key'));
                }
                /** @var $target_resource modResource */
                $oldFieldValue = $target_resource->get($field_name);
                if ($newFieldValue != $oldFieldValue) {
                    $target_resource->set($field_name, $newFieldValue);
                    $changed_resource_ids[] = $target_resource->get('id');
//                    $this->modx->log(modX::LOG_LEVEL_ERROR, $field_name." of {$target_resource->get('id')} changed from {$oldFieldValue}"
//                         ." to {$newFieldValue} based on {$source_resource->get('id')} with value {$sourceValue}");
                }
            }
        }
        return $changed_resource_ids;
    }

    public function OnResourceBeforeSort(array &$unsorted_nodes, array &$contexts) {
        // reorder data
        $changed_data = array();
        $handled_resource_ids = array();
        foreach($unsorted_nodes as $index => $data) {
            if (!isset($data['id']) || !isset($data['parent']) || !isset($data['order']) || !isset($data['context'])) {
                continue;
            }
            $node_id = (int) $data['id'];
            if (in_array($node_id, $handled_resource_ids)) continue;
            /** @var $source_resource modResource */
            $source_resource = $this->modx->getObject('modResource',$node_id);
            if (!($source_resource instanceof modResource)) {
                $this->modx->log(modX::LOG_LEVEL_ERROR, 'Babel: error loading sorted source resource '.$node_id);
                continue;
            }
            $changes = array();
            $changes['parent'] = ($source_resource->get('parent') == $data['parent']) ? false : true;
            $changes['order'] = ($source_resource->get('menuindex') == $data['order']) ? false : true;
            $changes['context'] = ($source_resource->get('context_key') == $data['context']) ? false : true;
            if (!in_array(true,$changes)) {
                continue;
            }
            if ($changes['context']) {
                $this->modx->log(modX::LOG_LEVEL_ERROR, 'Babel: cannot yet handle sorting across contexts for resource id '.$node_id);
                continue;
            }
            $linked_ids = $this->getLinkedResources($node_id);
            if (empty($linked_ids)) {
                continue;
            }
            // store the data for use in OnResourceSort so our changes are not overridden
            $changed_data[] = array(
                'new_data' => $data,
                'linked_ids' => $linked_ids,
                'changed_data' => $changes,
//                'source_resource' => $source_resource,
//                'original_data' => array(
//                    'parent' => $source_resource->get('parent'),
//                    'order' => $source_resource->get('menuindex'),
//                    'context' => $source_resource->get('context_key'),
//                ),
            );
            $handled_resource_ids[] = $node_id;
            $handled_resource_ids = array_merge($handled_resource_ids,$linked_ids);
        }
        $this->sort_resource_data_storage = $changed_data;
        return null;
    }

    public function OnResourceSort(array &$affected_resources) {
        // todo: check for changes only in one context
        foreach ($this->sort_resource_data_storage as $source_id => $stored_data) {
            // load data from the OnResourceBeforeSort event, which as of MODX 2.2.4-pl is the only way to find which data was actually changed
            $new_data = $stored_data['new_data'];
            $linked_ids = $stored_data['linked_ids'];
            $changes = $stored_data['changed_data'];
            // $source_resource = $stored_data['source_resource'];
            // $original_data = $stored_data['original_data'];

            // now update linked resources, overriding any sorting already done on them
            foreach($linked_ids as $linked_context => $linked_id) {
                $handled_resource_ids[] = $linked_id;
                if ($linked_id == $source_id || $linked_context == $new_data['context']) continue;
                /** @var $linked_resource modResource */
                $linked_resource = $this->modx->getObject('modResource',$linked_id);
                if (!($linked_resource instanceof modResource)) {
                    $this->modx->log(modX::LOG_LEVEL_ERROR, 'Babel: cannot load resource for linked resource id '.$linked_resource);
                    continue;
                }
                if ($changes['parent']) {
                    $parent_id = intval($this->getLinkedParentId($new_data['parent'], $linked_context));
                    $linked_resource->set('parent',$parent_id);
                    $this->modx->log(modX::LOG_LEVEL_INFO, 'Babel: updated linked resource id '.$linked_id.' with parent '.$parent_id);
                }
                if ($changes['order']) {
                    $linked_resource->set('menuindex',$new_data['order']);
                    $this->modx->log(modX::LOG_LEVEL_INFO, 'Babel: updated linked resource id '.$linked_id.' with order '.$new_data['order']);
                }
                $success = $linked_resource->save();
                if (!$success) {
                    $this->modx->log(modX::LOG_LEVEL_ERROR, 'Babel: failed to save linked resource id '.$linked_id);
                }
            }
        }
        $this->modx->cacheManager->refresh();
    }

    public function getLinkedParentId($source_parent_id, $context_key) {
        $newParentId = null;
        if ($source_parent_id) {
            $linked_parent_resources = $this->getLinkedResources($source_parent_id);
            if(isset($linked_parent_resources[$context_key])) {
                $newParentId = $linked_parent_resources[$context_key];
            }
        }
        return $newParentId;
    }

    /**
     * Works only on saved parents
     *
     * @param modResource|null $new_parent
     * @param modResource|null $old_parent
     */
    protected function updateLinkedParents(modResource &$new_parent=null, modResource &$old_parent=null) {
        if ($new_parent instanceof modResource) {
            $new_parent->set('is_folder',true);
            $new_parent->save();
        }
        // update folder status of old parent if it is newly empty or full
        /** @var $kid modResource */
        if ($old_parent instanceof modResource) {
            $kids = $old_parent->getMany('Children');
            if (empty($kids)) {
                $old_parent->set('is_folder', false);
                $old_parent->save();
            }
        }
    }

    /**
     * Returns an array with the context keys of the specified context's group.
     *
     * @param string $contextKey key of context.
     * @return array
     */
    public function getGroupContextKeys($contextKey) {
        $contextKeys = array();
        if(isset($this->config['contextKeyToGroup'][$contextKey]) && is_array($this->config['contextKeyToGroup'][$contextKey])) {
            $contextKeys = $this->config['contextKeyToGroup'][$contextKey];
        }
        return $contextKeys;
    }

    /**
     * Creates a duplicate of the specified resource in the specified context.
     *
     * @param modResource $resource
     * @param string $contextKey
     * @return modResource|null The new resource
     */
    public function duplicateResource(&$resource, $contextKey) {
        /* determine parent id of new resource */
        /* create new resource */
        /** @var $newResource modResource */
        $newResource = $this->modx->newObject($resource->get('class_key'));
        $newResource->fromArray($resource->toArray('', true), '', false, true);
        $newResource->set('id',0);
        $newResource->set('pagetitle', $resource->get('pagetitle').' '.$this->modx->lexicon('babel.translation_pending'));
        $newResource->set('createdby',$this->modx->user->get('id'));
        $newResource->set('createdon',time());
        $newResource->set('editedby',0);
        $newResource->set('editedon',0);
        $newResource->set('deleted',false);
        $newResource->set('deletedon',0);
        $newResource->set('deletedby',0);
        $newResource->set('published',false);
        $newResource->set('publishedon',0);
        $newResource->set('publishedby',0);
        $newResource->set('context_key', $contextKey);
        $parent_id = (int) $this->getLinkedParentId($resource->get('parent'), $contextKey);
        $newResource->set('parent', $parent_id);
        if($newResource->save()) {
            /* copy all TV values */
            $templateVarResources = $resource->getMany('TemplateVarResources');
            foreach ($templateVarResources as $oldTemplateVarResource) {
                /** @var $oldTemplateVarResource modTemplateVarResource */
                /** @var $newTemplateVarResource modTemplateVarResource */
                $newTemplateVarResource = $this->modx->newObject('modTemplateVarResource');
                $newTemplateVarResource->set('contentid',$newResource->get('id'));
                $newTemplateVarResource->set('tmplvarid',$oldTemplateVarResource->get('tmplvarid'));
                $newTemplateVarResource->set('value',$oldTemplateVarResource->get('value'));
                $newTemplateVarResource->save();
            }
            $this->updateLinkedParents($newResource->getOne('Parent'),null);
        } else {
            $this->modx->log(modX::LOG_LEVEL_ERROR, 'Could not duplicate resource: '.$resource->get('id').' in context: '.$contextKey);
            $newResource = null;
        }
        return $newResource;
    }

    /**
     * Creates an associative array which maps context keys to there
     * context groups out of an $contextKeyString
     *
     * @param string $contextKeyString example: ctx1,ctx2;ctx3,ctx4,ctx5;ctx5,ctx6
     *
     * @return array associative array which maps context keys to there
     * context groups.
     */
    public function decodeContextKeySetting($contextKeyString) {
        $contextKeyToGroup = array();
        if(!empty($contextKeyString)) {
            $contextGroups = explode(';', $contextKeyString);
            $contextGroups = array_map('trim', $contextGroups);
            foreach($contextGroups as $contextGroup) {
                $groupContextKeys = explode(',',$contextGroup);
                $groupContextKeys = array_map('trim', $groupContextKeys);
                foreach($groupContextKeys as $contextKey) {
                    if(!empty($contextKey)) {
                        $contextKeyToGroup[$contextKey] = $groupContextKeys;
                    }
                }
            }
        }
        return $contextKeyToGroup;
    }

    /**
     * Init/reset the Babel TV of the specified resource.
     *
     * @param modResource $resource resource object.
     *
     * @return array associative array with linked resources (array contains only the resource itself).
     */
    public function initBabelTv($resource) {
        $linkedResources = array ($resource->get('context_key') => $resource->get('id'));
        $this->updateBabelTv($resource->get('id'), $linkedResources, false);
        return $linkedResources;
    }

    /**
     * Init/reset the Babel TV of a resource specified by the id of the resource.
     *
     * @param int $resourceId id of resource (int).
     * @return array associative array with linked resources (array contains only the resource itself).
     */
    public function initBabelTvById($resourceId) {
        /** @var $resource modResource */
        $resource = $this->modx->getObject('modResource', $resourceId);
        return $this->initBabelTv($resource);
    }

    /**
     * Updates the Babel TV of the specified resource(s).
     *
     * @param mixed $resourceIds id of resource or array of resource ids which should be updated.
     * @param array $linkedResources associative array with linked resources: [contextKey] = resourceId
     * @param boolean $clearCache flag to empty cache after update.
     */
    public function updateBabelTv($resourceIds, $linkedResources, $clearCache = true) {
        if(!is_array($resourceIds)) {
            $resourceIds = array(intval($resourceIds));
        }
        $newValue = $this->encodeTranslationLinks($linkedResources);
        foreach($resourceIds as $resourceId){
            $this->babelTv->setValue($resourceId,$newValue);
        }
        $this->babelTv->save();
        if($clearCache) {
            $this->modx->cacheManager->refresh();
        }
        return;
    }

    /**
     * Returns an associative array of the linked resources of the specified resource.
     *
     * @param int $resourceId id of resource.
     *
     * @return array associative array with linked resources: [contextKey] = resourceId.
     */
    public function getLinkedResources($resourceId) {
        return $this->decodeTranslationLinks($this->babelTv->getValue($resourceId));
    }

    /**
     * Creates an associative array of linked resources out of string.
     *
     * @param string $linkedResourcesString string which contains the translation links: [contextKey1]:[resourceId1];[contextKey2]:[resourceId2]
     *
     * @return array associative array with linked resources: [contextKey] = resourceId.
     */
    public function decodeTranslationLinks($linkedResourcesString) {
        $linkedResources = array();
        if(!empty($linkedResourcesString)) {
            $contextResourcePairs = explode(';', $linkedResourcesString);
            foreach($contextResourcePairs as $contextResourcePair) {
                $contextResourcePair = explode(':', $contextResourcePair);
                $contextKey = $contextResourcePair[0];
                $resourceId = intval($contextResourcePair[1]);
                $linkedResources[$contextKey] = $resourceId;
            }
        }
        return $linkedResources;
    }

    /**
     * Creates an string which contains the translation links out of an associative array.
     *
     * @param array $linkedResources associative array with linked resources: [contextKey] = resourceId
     * @return string which contains the translation links: [contextKey1]:[resourceId1];[contextKey2]:[resourceId2]
     */
    public function encodeTranslationLinks($linkedResources) {
        if(!is_array($linkedResources)) {
            return null;
        }
        $contextResourcePairs = array();
        foreach($linkedResources as $contextKey => $resourceId){
            $contextResourcePairs[] = $contextKey.':'.intval($resourceId);
        }
        return implode(';', $contextResourcePairs);
    }

    /**
     * Removes all translation links to the specified resource.
     *
     * @param int $resourceId id of resource.
     */
    public function removeLanguageLinksToResource($resourceId) {
        /* search for resource which contain a ':$resourceId' in their Babel TV */
        $templateVarResources = $this->modx->getCollection('modTemplateVarResource', array(
            'value:LIKE' => '%:'.$resourceId.'%'));
        if(!is_array($templateVarResources)) {
            return;
        }
        foreach($templateVarResources as $templateVarResource) {
            /** @var $templateVarResource modTemplateVarResource  */
            /* go through each resource and remove the link of the specified resource */
            $oldValue = $templateVarResource->get('value');
            $linkedResources = $this->decodeTranslationLinks($oldValue);
            /* array maps context keys to resource ids
             * -> search for the context key of the specified resource id */
            $contextKey = array_search($resourceId, $linkedResources);
            unset($linkedResources[$contextKey]);
            $newValue = $this->encodeTranslationLinks($linkedResources);
            $templateVarResource->set('value', $newValue);
            $templateVarResource->save();
        }
    }

    /**
     * Removes all translation links to the specified context.
     *
     * @param int $contextKey key of context.
     */
    public function removeLanguageLinksToContext($contextKey) {
        /* search for resource which contain a '$contextKey:' in their Babel TV */
        $templateVarResources = $this->modx->getCollection('modTemplateVarResource', array(
            'value:LIKE' => '%'.$contextKey.':%'));
        if(!is_array($templateVarResources)) {
            return;
        }
        foreach($templateVarResources as $templateVarResource) {
            /** @var $templateVarResource modTemplateVarResource  */
            /* go through each resource and remove the link of the specified context */
            $oldValue = $templateVarResource->get('value');
            $linkedResources = $this->decodeTranslationLinks($oldValue);
            /* array maps context keys to resource ids */
            unset($linkedResources[$contextKey]);
            $newValue = $this->encodeTranslationLinks($linkedResources);
            $templateVarResource->set('value', $newValue);
            $templateVarResource->save();
        }
        /* finaly clean the babel.contextKeys setting */
        /** @var $setting modSystemSetting */
        $setting = $this->modx->getObject('modSystemSetting',array(
            'key' => 'babel.contextKeys'));
        if($setting) {
            /* remove all spaces */
            $newValue = str_replace(' ','',$setting->get('value'));
            /* replace context key with leading comma */
            $newValue = str_replace(','.$contextKey,'',$newValue);
            /* replace context key without leading comma (if still present) */
            $newValue = str_replace($contextKey,'',$newValue);
            $setting->set('value', $newValue);
            if($setting->save()) {
                $this->modx->reloadConfig();
                $this->modx->cacheManager->deleteTree($this->modx->getOption('core_path',null,MODX_CORE_PATH).'cache/mgr/smarty/',array(
                   'deleteTop' => false,
                    'skipDirs' => false,
                    'extensions' => array('.cache.php','.php'),
                ));
            }
        }
    }

    /**
    * Gets a Chunk and caches it; also falls back to file-based templates
    * for easier debugging.
    *
    * @access public
    * @param string $name The name of the Chunk
    * @param array $properties The properties for the Chunk
    * @return string The processed content of the Chunk
    */
    public function getChunk($name,array $properties = array()) {
        $chunk = null;
        if (!isset($this->chunks[$name])) {
            $chunk = $this->modx->getObject('modChunk',array('name' => $name),true);
            if (empty($chunk)) {
                $chunk = $this->_getTplChunk($name,$this->config['chunkSuffix']);
                if ($chunk == false) return false;
            }
            $this->chunks[$name] = $chunk->getContent();
        } else {
            $o = $this->chunks[$name];
            $chunk = $this->modx->newObject('modChunk');
            $chunk->setContent($o);
        }
        $chunk->setCacheable(false);
        return $chunk->process($properties);
    }
    
    /**
    * Returns a modChunk object from a template file.
    *
    * @access private
    * @param string $name The name of the Chunk. Will parse to name.chunk.tpl by default.
    * @param string $suffix The suffix to add to the chunk filename.
    * @return modChunk/boolean Returns the modChunk object if found, otherwise
    * false.
    */
    private function _getTplChunk($name,$suffix = '.chunk.tpl') {
        $chunk = false;
        $f = $this->config['chunksPath'].strtolower($name).$suffix;
        if (file_exists($f)) {
            $o = file_get_contents($f);
            /** @var $chunk modChunk */
            $chunk = $this->modx->newObject('modChunk');
            $chunk->set('name',$name);
            $chunk->setContent($o);
        }
        return $chunk;
    }


}
