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

class AclManagerAppController extends AppController {

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
	}
}

