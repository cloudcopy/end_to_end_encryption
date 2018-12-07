<?php
/**
 * @copyright Copyright (c) 2017 Bjoern Schiessle <bjoern@schiessle.org>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace OCA\EndToEndEncryption\Connector\Sabre;

use OC\AppFramework\Http;
use OCA\DAV\Connector\Sabre\Directory;
use OCA\DAV\Connector\Sabre\File;
use OCA\EndToEndEncryption\IKeyStorage;
use OCA\EndToEndEncryption\KeyStorage;
use OCA\EndToEndEncryption\LockManager;
use OCA\DAV\Connector\Sabre\Exception\FileLocked;
use OCA\DAV\Connector\Sabre\Exception\Forbidden;
use OCA\EndToEndEncryption\UserAgentManager;
use OCP\AppFramework\IAppContainer;
use OCP\Files\FileInfo;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Sabre\DAV\Exception\Conflict;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\INode;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;

class LockPlugin extends ServerPlugin {

	/* @var Server */
	private $server;

	/** @var IRootFolder */
	private $rootFolder;

	/** @var IUserSession */
	private $userSession;

	/** @var LockManager */
	private $lockManager;

	/** @var UserAgentManager */
	private $userAgentManager;

	/** @var IURLGenerator */
	private $urlGenerator;

	/**
	 * Should plugin be applied to the current node?
	 * Only apply it to files and directories, not to contacts or calendars
	 *
	 * @var array
	 */
	private $applyPlugin;

	/** @var IKeyStorage */
	private $keyStorage;

	/**
	 * LockPlugin constructor.
	 *
	 * @param IRootFolder $rootFolder
	 * @param IUserSession $userSession
	 * @param LockManager $lockManager
	 * @param UserAgentManager $userAgentManager
	 * @param IURLGenerator $urlGenerator
	 * @param IAppContainer $appContainer
	 * @param IConfig $config
	 * @throws \OCP\AppFramework\QueryException
	 */
	public function __construct(IRootFolder $rootFolder,
								IUserSession $userSession,
								LockManager $lockManager,
								UserAgentManager $userAgentManager,
								IURLGenerator $urlGenerator,
								IAppContainer $appContainer,
								IConfig $config
	) {
		$this->rootFolder = $rootFolder;
		$this->userSession = $userSession;
		$this->lockManager = $lockManager;
		$this->userAgentManager = $userAgentManager;
		$this->urlGenerator = $urlGenerator;
		$this->applyPlugin = [];


		$keyStorage = $config->getSystemValue('e2e_encryption_key_storage', KeyStorage::class);
		$this->keyStorage = $appContainer->query($keyStorage);

	}

	/**
	 * {@inheritdoc}
	 */
	public function initialize(Server $server) {
		$this->server = $server;
		$this->server->on('beforeMethod', [$this, 'checkLock'], 200);
	}

	/**
	 * Check if a file is locked for end-to-end encryption before trying to download it
	 *
	 * @param RequestInterface $request
	 * @throws Conflict
	 * @throws FileLocked
	 * @throws Forbidden
	 * @throws NotFound
	 */
	public function checkLock(RequestInterface $request) {

		$node = $this->getNode($request->getPath(), $request->getMethod());

		$url = $request->getAbsoluteUrl();

		// only apply the plugin to files/directory, not to contacts or calendars
		if (!$this->isFile($url, $node)) {
			return;
		}

		$userAgent = $request->getHeader('user-agent');

		$this->checkUserAgent($userAgent, $node->getPath());
		if ($request->getMethod() === 'GET') {
			if ($this->lockManager->isLocked($node->getId(), '')) {
				throw new FileLocked('file is locked', Http::STATUS_FORBIDDEN);
			}
		}
	}

	/**
	 * get SabreDAV Node
	 *
	 * @param string $path
	 * @param string $method
	 * @return INode
	 * @throws Conflict
	 * @throws NotFound
	 */
	protected function getNode($path, $method) {
		if ($method === 'GET' || $method === 'PROPFIND' || $method === 'HEAD') {
			return $this->server->tree->getNodeForPath($path);
		}

		return $this->getNodeForPath($path);

	}

	/**
	 * Check if user agent is allowed to access a end-to-end encrypted folder
	 *
	 * @param string $userAgent
	 * @param string $path
	 * @throws Forbidden
	 * @throws NotFound
	 */
	protected function checkUserAgent($userAgent, $path) {

		// if the key storage allows to access keys over the web interface
		// we can return directly
		if ($this->keyStorage->getCapabilities() & IKeyStorage::WEB_ACCESS) {
			return;
		}

		if (!$this->userAgentManager->supportsEndToEndEncryption($userAgent)) {
			$node = $this->getFileNode($path);
			while ($node->isEncrypted() === false || $node->getType() === FileInfo::TYPE_FILE) {
				$node = $node->getParent();
				if ($node->getPath() === '/') {
					// top-level folder reached
					return;
				}
			}

			throw new Forbidden('client "' . $userAgent . '" is not allowed to access end-to-end encrypted content');
		}
	}

	/**
	 * Get DAV Node for a given path, if the path doesn't exists we try the parent
	 *
	 * @param $path
	 * @return \Sabre\DAV\INode
	 * @throws Conflict
	 */
	protected function getNodeForPath($path) {

		if ($this->server->tree->nodeExists($path)) {
			return $this->server->tree->getNodeForPath($path);
		}

		// maybe we are in the process in creating a new node, try the parent
		$parent = dirname($path);
		$parent = ($parent === '.') ? '/' : $parent;
		if ($this->server->tree->nodeExists($parent)) {
			return $this->server->tree->getNodeForPath($parent);
		}

		// If neither the actual node, nor the parent exists we throw a exception.
		// According to the WebDAV specification it should result in 409 (conflict)
		throw new Conflict();
	}

	/**
	 * get file system node of requested file
	 *
	 * @param string $path
	 * @return Node
	 *
	 * @throws NotFound
	 */
	protected function getFileNode($path) {
		try {
			$uid = $this->userSession->getUser()->getUID();
			$userRoot = $this->rootFolder->getUserFolder($uid);
			$node = $userRoot->get($path);
			return $node;
		} catch (\Exception $e) {
			throw new NotFound('file not found', Http::STATUS_NOT_FOUND, $e);
		}
	}

	/**
	 * check if we process a file or directory. This plugin should ignore calendars
	 * and contacts
	 *
	 * @param string $url
	 * @param INode $node
	 * @return bool
	 */
	protected function isFile($url, INode $node) {

		if (isset($this->applyPlugin[$url])) {
			return $this->applyPlugin[$url];
		}

		// check if this is a regular file or directory
		$this->applyPlugin[$url] = (($node instanceof File) || ($node instanceof Directory));

		return $this->applyPlugin[$url];
	}

}
