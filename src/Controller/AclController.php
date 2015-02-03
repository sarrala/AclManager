<?php
/**
 * Acl Manager
 *
 * A CakePHP Plugin to manage Acl
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @author        Frédéric Massart - FMCorz.net
 * @copyright     Copyright 2011, Frédéric Massart
 * @link          http://github.com/FMCorz/AclManager
 * @license       MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
namespace AclManager\Controller;

use AclManager\Controller\AclManagerAppController;
use Cake\Event\Event;
use Cake\Core\Configure;
use Cake\Controller\Controller;
use Cake\Network\Request;
use Cake\Utility\Inflector;
use Cake\Filesystem\Folder;
use Cake\Core\App;
use Cake\Utility\Hash;

function dbg($data, $msg = 'Loading') {
	$value = var_export($data,true);
	echo "<div><b>DEBUG:</b> $msg $value</div>";
}

class AclController extends AclManagerAppController {

	public $paginate = [];
	protected $_authorizer = null;
	protected $acos = [];
	
	/**
	 * beforeFitler
	 */
	public function beforeFilter(Event $event) {
		parent::beforeFilter($event);
		
		/**
		 * Loading required Model
		 */
		$aros = Configure::read('AclManager.models');
		foreach ($aros as $aro) {
			dbg($aro);
			$this->loadModel($aro);
		}
		/**
		 * Pagination
		 */
		$aros = Configure::read('AclManager.aros');
		foreach ($aros as $aro) {
			$limit = Configure::read("AclManager.{$aro}.limit");
			$limit = empty($limit) ? 4 : $limit;
			$this->paginate[$this->{$aro}->alias()] = array(
				'recursive' => -1,
				'limit' => $limit
			);
		}
	}

	/**
	 * Index action
	 */
	public function index() {
	}

	/**
	 * Manage Permissions
	 */
	public function permissions() {
		
		// Saving permissions
		if ($this->request->is('post') || $this->request->is('put')) {
			$perms =  isset($this->request->data['Perms']) ? $this->request->data['Perms'] : array();
			foreach ($perms as $aco => $aros) {
				$action = str_replace(":", "/", $aco);
				foreach ($aros as $node => $perm) {
					list($model, $id) = explode(':', $node);
					$node = array('model' => $model, 'foreign_key' => $id);
					if ($perm == 'allow') {
						$this->Acl->allow($node, $action);
					}
					elseif ($perm == 'inherit') {
						$this->Acl->inherit($node, $action);
					}
					elseif ($perm == 'deny') {
						$this->Acl->deny($node, $action);
					}
				}
			}
		}
		
		$model = isset($this->request->params['named']['aro']) ? $this->request->params['named']['aro'] : null;
		if (!$model || !in_array($model, Configure::read('AclManager.aros'))) {
			$model = Configure::read('AclManager.aros');
			$model = $model[0];
		}
		
		$Aro = $this->{$model};
		$aros = $this->paginate( $this->AclManager->Aco->find('all')->hydrate(false) );// $Aro->alias());
		//var_dump($aros); die();
		$permKeys = $this->_getKeys();
		
		/**
		 * Build permissions info
		 */
		$acos_query = $this->AclManager->Aco->find('all', array('order' => 'Acos.lft ASC'))->contain(['aros']);
		$acos = $acos_query->hydrate(false)->toArray();
		$perms = array();
		$parents = array();
		$this->acos = [];
		foreach ($acos as $key => $data) {
			$aco = array('Aco' => &$acos[$key]);
			$aco = array('Aco' => $data, 'Aro' => $data['aros'], 'Action' => '');
			$this->acos[$key] = $aco;
			$id = $aco['Aco']['id'];
			
			// Generate path
			if ($aco['Aco']['parent_id'] && isset($parents[$aco['Aco']['parent_id']])) {
				$parents[$id] = $parents[$aco['Aco']['parent_id']] . '/' . $aco['Aco']['alias'];
			} else {
				$parents[$id] = $aco['Aco']['alias'];
			}
			$aco['Action'] = $parents[$id];
			
			// Fetching permissions per ARO
			$acoNode = $aco['Action'];
			foreach($aros as $aro) {
				$aroId = $aro['id']; //[$Aro->alias()][$Aro->primaryKey()];
				$evaluate = $this->_evaluate_permissions($permKeys, array('id' => $aroId, 'alias' => $Aro->alias()), $aco, $key);
				
				$perms[str_replace('/', ':', $acoNode)][$Aro->alias() . ":" . $aroId . '-inherit'] = $evaluate['inherited'];
				$perms[str_replace('/', ':', $acoNode)][$Aro->alias() . ":" . $aroId] = $evaluate['allowed'];
				//var_dump($aro); var_dump($perms);die();
			}
		}
		$this->request->data = array('Perms' => $perms);
		$this->set('aroAlias', $Aro->alias());
		$this->set('aroDisplayField', $Aro->displayField());
		$acos = $this->acos;
		$this->set(compact('acos', 'aros'));
	}
	
	/**
	 * Recursive function to find permissions avoiding slow $this->AclManager->check().
	 */
	private function _evaluate_permissions($permKeys, $aro, $aco, $aco_index) {
		$path = "/Aro[model={$aro['alias']}][foreign_key={$aro['id']}]/Permission/.";
		$permissions = \Cake\Utility\Hash::extract($aco, $path);
		//dbg($aco, 'ACO Data');
		//dbg($path, 'Path');
		//dbg($permissions, 'Permissions');
		//die();
		$permissions = array_shift($permissions);		
		$allowed = false;
		$inherited = false;
		$inheritedPerms = array();
		$allowedPerms = array();
		
		/**
		 * Manually checking permission
		 * Part of this logic comes from DbAcl::check()
		 */
		foreach ($permKeys as $key) {
			if (!empty($permissions)) {
				if ($permissions[$key] == -1) {
					$allowed = false;
					break;
				} elseif ($permissions[$key] == 1) {
					$allowedPerms[$key] = 1;
				} elseif ($permissions[$key] == 0) {
					$inheritedPerms[$key] = 0;
						}
			} else {
				$inheritedPerms[$key] = 0;
					}
				}
		
		if (count($allowedPerms) === count($permKeys)) {
			$allowed = true;
		} elseif (count($inheritedPerms) === count($permKeys)) {
			if ($aco['Aco']['parent_id'] == null) {
				$this->lookup +=1;
				$acoNode = (isset($aco['Action'])) ? $aco['Action'] : null;
				$aroNode = array('model' => $aro['alias'], 'foreign_key' => $aro['id']);
				if ($aroNode['foreign_key']) {
					try {
						$allowed = $this->AclManager->check($aroNode, $acoNode);
					} catch (\Exception $e) {}
				}
				$this->acos[$aco_index]['Aco']['evaluated'][$aro['id']] = array(
					'allowed' => $allowed,
					'inherited' => true
				);
			}
			else {
				/**
				 * Do not use Set::extract here. First of all it is terribly slow, 
				 * besides this we need the aco array index ($key) to cache are result.
				 */
				foreach ($this->acos as $key => $a) {
					if ($a['Aco']['id'] == $aco['Aco']['parent_id']) {
						$parent_aco = $a;
						break;
					}
				}
				// Return cached result if present
				if (isset($parent_aco['evaluated'][$aro['id']])) {
					return $parent_aco['evaluated'][$aro['id']];
				}
				
				// Perform lookup of parent aco
				//dbg( $permKeys, 'PermKeys' );
				//dbg( $aro, 'Aro' );
				//dbg( $parent_aco, 'Parent ACO' );
				//dbg( $key, 'Key' );
				$evaluate = $this->_evaluate_permissions($permKeys, $aro, $parent_aco, $key);
				
				// Store result in acos array so we need less recursion for the next lookup
				$this->acos[$key]['Aco']['evaluated'][$aro['id']] = $evaluate;
				$this->acos[$key]['Aco']['evaluated'][$aro['id']]['inherited'] = true;
				
				$allowed = $evaluate['allowed'];
			}
			$inherited = true;
		}
		
		return array(
			'allowed' => $allowed,
			'inherited' => $inherited,
		);
	}

	/**
	 * Gets the action from Authorizer
	 */
	protected function _action($request = array(), $path = '/:plugin/:controller/:action') {
		$plugin = empty($request['plugin']) ? null : Inflector::camelize($request['plugin']) . '/';
		$params = array_merge(array('controller' => null, 'action' => null, 'plugin' => null), $request);
		$request = new \Cake\Network\Request();
		$request->addParams($params);
		$authorizer = $this->_getAuthorizer();
		if (empty($this->_authorizer)) {
			$this->Flash->error(__("ActionAuthorizer could not be found"));
			return $this->redirect($this->referer());
		}
		return $authorizer->action($request, $path);
	}

	/**
	 * Build ACO node
	 *
	 * @return node
	 */
	protected function _buildAcoNode($alias, $parent_id = null) {
		if (is_array($parent_id)) {
			$parent_id = $parent_id[0]['Aco']['id'];
		}
		$aco = $this->AclManager->Aco->newEntity(array('alias' => $alias, 'parent_id' => $parent_id));
		$this->AclManager->Aco->save( $aco );
		return array(array('Aco' => array('id' => $aco->id)));
	}

	/**
	 * Returns all the Actions found in the Controllers
	 * 
	 * Ignores:
	 * - protected and private methods (starting with _)
	 * - Controller methods
	 * - methods matching Configure::read('AclManager.ignoreActions')
	 * 
	 * @return array('Controller' => array('action1', 'action2', ... ))
	 */
	protected function _getActions() {
		$ignore = Configure::read('AclManager.ignoreActions');
		$methods = get_class_methods('Controller');
		foreach($methods as $method) {
			$ignore[] = $method;
		}
		
		$controllers = $this->_getControllers();
		$actions = array();
		foreach ($controllers as $controller) {
		    
		    list($plugin, $name) = pluginSplit($controller);
			
		    $methods = get_class_methods($name . "Controller");
			$methods = array_diff($methods, $ignore);
			foreach ($methods as $key => $method) {
				if (strpos($method, "_") === 0 || in_array($controller . '/' . $method, $ignore)) {
					unset($methods[$key]);
				}
			}
			$actions[$controller] = $methods;
		}
		
		return $actions;
	}

	/**
	 * Returns all the ACOs including their path
	 */
	protected function _getAcos() {
		$acos_query = $this->AclManager->Aco->find('all', array('order' => 'Acos.lft ASC', 'recursive' => -1));
		$acos = $acos_query->hydrate(false)->toArray();
		$parents = array();
		foreach ($acos as $key => $data) {
			
			$aco =& $acos[$key];
			$id = $aco['id'];
			
			// Generate path
			if ($aco['parent_id'] && isset($parents[$aco['parent_id']])) {
				$parents[$id] = $parents[$aco['parent_id']] . '/' . $aco['alias'];
			} else {
				$parents[$id] = $aco['alias'];
			}
			$aco['action'] = $parents[$id];
		}
		return $acos;
	}

	/**
	 * Gets the Authorizer object from Auth
	 */
	protected function _getAuthorizer() {
		if (!is_null($this->_authorizer)) {
			return $this->_authorizer;
		}
		$authorzeObjects = $this->Auth->_authorizeObjects;
		foreach ($authorzeObjects as $object) {
			if (!$object instanceOf ActionsAuthorize) {
				continue;
			}
			$this->_authorizer = $object; 
			break;
		}
		return $this->_authorizer;
	}

	/**
	 * Get a list of controllers in the app and plugins.
	 *
	 * Returns an array of path => import notation.
	 *
	 * @param string $plugin Name of plugin to get controllers for
	 * @return array
	 **/
	public function _getControllers($plugin = null)
	{
		if (!$plugin) {
			$path = App::path('Controller');
			$dir = new Folder($path[0]);
			$controllers = $dir->findRecursive('.*Controller\.php');
			unset($controllers[0]);
		} else {
			$path = App::path('Controller', $plugin);
			$dir = new Folder($path[0]);
			$controllers = $dir->findRecursive('.*Controller\.php');
		}
		return $controllers;
	}
	
	/**
	 * Returns permissions keys in Permission schema
	 * @see DbAcl::_getKeys()
	 */
	protected function _getKeys() {
		/* FIXME: What was actual reason for this method? */
		return ['id', 'aro_id', 'aco_id'];
		
		$keys = $this->AclManager->Aro->Permission->schema();
		$newKeys = array();
		$keys = array_keys($keys);
		foreach ($keys as $key) {
			if (!in_array($key, array('id', 'aro_id', 'aco_id'))) {
				$newKeys[] = $key;
			}
		}
		return $newKeys;
	}
	
	/**
	 * Returns an array without the corresponding action
	 */
	protected function _removeActionFromAcos($acos, $action) {
		foreach ($acos as $key => $aco) {
			if ($aco['Aco']['action'] == $action) {
				unset($acos[$key]);
				break;
			}
		}
		return $acos;
	}
}
