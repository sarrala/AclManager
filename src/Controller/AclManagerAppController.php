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

use App\Controller\AppController;
use Cake\Event\Event;
use Cake\Core\Configure;
use Acl\Controller\Component\AclComponent;
use Cake\Controller\ComponentRegistry;

abstract class AclManagerAppController extends AppController {

	protected $AclManager = null;
	
	/**
	 * beforeFitler
	 */
	public function beforeFilter(Event $event) {
		parent::beforeFilter($event);
		
		/**
		 * Force prefix
		 */
		$prefix = Configure::read('AclManager.prefix');
		$routePrefix = isset($this->request->params['prefix']) ? $this->request->params['prefix'] : false;
		if ($prefix && $prefix != $routePrefix) {
			$this->redirect($this->request->referer());
		} 
		elseif ($prefix) {
			$this->request->params['action'] = str_replace($prefix . "_", "", $this->request->params['action']);
			$this->view = str_replace($prefix . "_", "", $this->view);
		}
		
		/*
		 * Load Acl tables
		 */
		$registry = new ComponentRegistry();
		$this->AclManager = new AclComponent($registry);
		
		/* Load other plugins / helpers */
		$this->loadComponent('Flash');
	}
	
}

