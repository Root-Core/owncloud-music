<?php

/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Morris Jobke 2013, 2014
 * @copyright Pauli Järvinen 2017 - 2020
 */

namespace OCA\Music\Controller;

use \OCP\AppFramework\Controller;
use \OCP\AppFramework\Http\JSONResponse;
use \OCP\IRequest;
use \OCP\IURLGenerator;

use \OCA\Music\AppFramework\BusinessLayer\BusinessLayer;
use \OCA\Music\AppFramework\BusinessLayer\BusinessLayerException;
use \OCA\Music\AppFramework\Core\Logger;
use \OCA\Music\Middleware\AmpacheException;

use \OCA\Music\BusinessLayer\AlbumBusinessLayer;
use \OCA\Music\BusinessLayer\ArtistBusinessLayer;
use \OCA\Music\BusinessLayer\GenreBusinessLayer;
use \OCA\Music\BusinessLayer\Library;
use \OCA\Music\BusinessLayer\PlaylistBusinessLayer;
use \OCA\Music\BusinessLayer\TrackBusinessLayer;

use \OCA\Music\Db\Album;
use \OCA\Music\Db\AmpacheUserMapper;
use \OCA\Music\Db\AmpacheSession;
use \OCA\Music\Db\AmpacheSessionMapper;
use \OCA\Music\Db\Artist;
use \OCA\Music\Db\SortBy;

use \OCA\Music\Http\ErrorResponse;
use \OCA\Music\Http\FileResponse;
use \OCA\Music\Http\XMLResponse;

use \OCA\Music\Utility\AmpacheUser;
use \OCA\Music\Utility\CoverHelper;
use \OCA\Music\Utility\Random;
use \OCA\Music\Utility\Util;

class AmpacheController extends Controller {
	private $ampacheUserMapper;
	private $ampacheSessionMapper;
	private $albumBusinessLayer;
	private $artistBusinessLayer;
	private $genreBusinessLayer;
	private $playlistBusinessLayer;
	private $trackBusinessLayer;
	private $library;
	private $ampacheUser;
	private $urlGenerator;
	private $rootFolder;
	private $l10n;
	private $coverHelper;
	private $random;
	private $logger;
	private $jsonMode;

	const SESSION_EXPIRY_TIME = 6000;
	const ALL_TRACKS_PLAYLIST_ID = 10000000;
	const API_VERSION = 400001;
	const API_MIN_COMPATIBLE_VERSION = 350001;

	public function __construct($appname,
								IRequest $request,
								$l10n,
								IURLGenerator $urlGenerator,
								AmpacheUserMapper $ampacheUserMapper,
								AmpacheSessionMapper $ampacheSessionMapper,
								AlbumBusinessLayer $albumBusinessLayer,
								ArtistBusinessLayer $artistBusinessLayer,
								GenreBusinessLayer $genreBusinessLayer,
								PlaylistBusinessLayer $playlistBusinessLayer,
								TrackBusinessLayer $trackBusinessLayer,
								Library $library,
								AmpacheUser $ampacheUser,
								$rootFolder,
								CoverHelper $coverHelper,
								Random $random,
								Logger $logger) {
		parent::__construct($appname, $request);

		$this->ampacheUserMapper = $ampacheUserMapper;
		$this->ampacheSessionMapper = $ampacheSessionMapper;
		$this->albumBusinessLayer = $albumBusinessLayer;
		$this->artistBusinessLayer = $artistBusinessLayer;
		$this->genreBusinessLayer = $genreBusinessLayer;
		$this->playlistBusinessLayer = $playlistBusinessLayer;
		$this->trackBusinessLayer = $trackBusinessLayer;
		$this->library = $library;
		$this->urlGenerator = $urlGenerator;
		$this->l10n = $l10n;

		// used to share user info with middleware
		$this->ampacheUser = $ampacheUser;

		// used to deliver actual media file
		$this->rootFolder = $rootFolder;

		$this->coverHelper = $coverHelper;
		$this->random = $random;
		$this->logger = $logger;
	}

	public function setJsonMode($useJsonMode) {
		$this->jsonMode = $useJsonMode;
	}

	public function ampacheResponse($content) {
		if ($this->jsonMode) {
			return new JSONResponse(self::prepareResultForJsonApi($content));
		} else {
			return new XMLResponse(self::prepareResultForXmlApi($content), ['id', 'index', 'count', 'code']);
		}
	}

	/**
	 * @NoAdminRequired
	 * @PublicPage
	 * @NoCSRFRequired
	 */
	public function xmlApi($action, $user, $timestamp, $auth, $filter, $exact, $limit, $offset, $id) {
		// differentation between xmlApi and jsonApi is made already by the middleware
		return $this->dispatch($action, $user, $timestamp, $auth, $filter, $exact, $limit, $offset, $id);
	}

	/**
	 * @NoAdminRequired
	 * @PublicPage
	 * @NoCSRFRequired
	 */
	public function jsonApi($action, $user, $timestamp, $auth, $filter, $exact, $limit, $offset, $id) {
		// differentation between xmlApi and jsonApi is made already by the middleware
		return $this->dispatch($action, $user, $timestamp, $auth, $filter, $exact, $limit, $offset, $id);
	}

	protected function dispatch($action, $user, $timestamp, $auth, $filter, $exact, $limit, $offset, $id) {
		$this->logger->log("Ampache action '$action' requested", 'debug');

		$limit = self::validateLimitOrOffset($limit);
		$offset = self::validateLimitOrOffset($offset);

		switch ($action) {
			case 'handshake':
				return $this->handshake($user, $timestamp, $auth);
			case 'goodbye':
				return $this->goodbye($auth);
			case 'ping':
				return $this->ping($auth);
			case 'get_indexes':
				return $this->get_indexes($filter, $limit, $offset);
			case 'stats':
				return $this->stats($limit, $offset, $auth);
			case 'artists':
				return $this->artists($filter, $exact, $limit, $offset, $auth);
			case 'artist':
				return $this->artist($filter, $auth);
			case 'artist_albums':
				return $this->artist_albums($filter, $auth);
			case 'album_songs':
				return $this->album_songs($filter, $auth);
			case 'albums':
				return $this->albums($filter, $exact, $limit, $offset, $auth);
			case 'album':
				return $this->album($filter, $auth);
			case 'artist_songs':
				return $this->artist_songs($filter, $auth);
			case 'songs':
				return $this->songs($filter, $exact, $limit, $offset, $auth);
			case 'song':
				return $this->song($filter, $auth);
			case 'search_songs':
				return $this->search_songs($filter, $auth);
			case 'playlists':
				return $this->playlists($filter, $exact, $limit, $offset);
			case 'playlist':
				return $this->playlist($filter);
			case 'playlist_songs':
				return $this->playlist_songs($filter, $limit, $offset, $auth);
			case 'playlist_generate':
				return $this->playlist_generate($filter, $limit, $offset, $auth);
			case 'tags':
				return $this->tags($filter, $exact, $limit, $offset);
			case 'tag':
				return $this->tag($filter);
			case 'tag_artists':
				return $this->tag_artists($filter, $limit, $offset, $auth);
			case 'tag_albums':
				return $this->tag_albums($filter, $limit, $offset, $auth);
			case 'tag_songs':
				return $this->tag_songs($filter, $limit, $offset, $auth);
			case 'flag':
				return $this->flag();
			case 'download':
				return $this->download($id); // args 'type' and 'format' not supported
			case 'stream':
				return $this->stream($id, $offset); // args 'type', 'bitrate', 'format', and 'length' not supported

			# non Ampache API actions
			case '_get_album_cover':
				return $this->_get_album_cover($id);
			case '_get_artist_cover':
				return $this->_get_artist_cover($id);
		}

		$this->logger->log("Unsupported Ampache action '$action' requested", 'warn');
		throw new AmpacheException('Action not supported', 405);
	}

	/***********************
	 * Ampahce API methods *
	 ***********************/

	protected function handshake($user, $timestamp, $auth) {
		$currentTime = \time();
		$expiryDate = $currentTime + self::SESSION_EXPIRY_TIME;

		$this->checkHandshakeTimestamp($timestamp, $currentTime);
		$this->checkHandshakeAuthentication($user, $timestamp, $auth);
		$token = $this->startNewSession($user, $expiryDate);

		$currentTimeFormated = \date('c', $currentTime);
		$expiryDateFormated = \date('c', $expiryDate);

		return $this->ampacheResponse([
			'auth' => $token,
			'api' => self::API_VERSION,
			'update' => $currentTimeFormated,
			'add' => $currentTimeFormated,
			'clean' => $currentTimeFormated,
			'songs' => $this->trackBusinessLayer->count($user),
			'artists' => $this->artistBusinessLayer->count($user),
			'albums' => $this->albumBusinessLayer->count($user),
			'playlists' => $this->playlistBusinessLayer->count($user) + 1, // +1 for "All tracks"
			'session_expire' => $expiryDateFormated,
			'tags' => $this->genreBusinessLayer->count($user),
			'videos' => 0,
			'catalogs' => 0
		]);
	}

	protected function goodbye($auth) {
		// getting the session should not throw as the middleware has already checked that the token is valid
		$session = $this->ampacheSessionMapper->findByToken($auth);
		$this->ampacheSessionMapper->delete($session);

		return $this->ampacheResponse(['success' => "goodbye: $auth"]);
	}

	protected function ping($auth) {
		$response = [
			// TODO: 'server' => Music app version,
			'version' => self::API_VERSION,
			'compatible' => self::API_MIN_COMPATIBLE_VERSION
		];

		if (!empty($auth)) {
			// getting the session should not throw as the middleware has already checked that the token is valid
			$session = $this->ampacheSessionMapper->findByToken($auth);
			$response['session_expire'] = \date('c', $session->getExpiry());
		}

		return $this->ampacheResponse($response);
	}

	protected function get_indexes($filter, $limit, $offset) {
		// TODO: args $add, $update
		$type = $this->getRequiredParam('type');

		$businessLayer = $this->getBusinessLayer($type);
		$entities = $this->findEntities($businessLayer, $filter, false, $limit, $offset);
		return $this->renderEntitiesIndex($entities, $type);
	}

	protected function stats($limit, $offset, $auth) {
		$type = $this->getRequiredParam('type');
		$filter = $this->getRequiredParam('filter');
		$userId = $this->ampacheUser->getUserId();

		if (!\in_array($type, ['song', 'album', 'artist'])) {
			throw new AmpacheException("Unsupported type $type", 400);
		}
		$businessLayer = $this->getBusinessLayer($type);

		switch ($filter) {
			case 'newest':
				$entities = $businessLayer->findAll($userId, SortBy::Newest, $limit, $offset);
				break;
			case 'flagged':
				$entities = $businessLayer->findAllStarred($userId, $limit, $offset);
				break;
			case 'random':
				$entities = $businessLayer->findAll($userId, SortBy::None);
				$indices = $this->random->getIndices(\count($entities), $offset, $limit, $userId, 'ampache_stats_'.$type);
				$entities = Util::arrayMultiGet($entities, $indices);
				break;
			case 'highest':		//TODO
			case 'frequent':	//TODO
			case 'recent':		//TODO
			case 'forgotten':	//TODO
			default:
				throw new AmpacheException("Unsupported filter $filter", 400);
		}

		return $this->renderEntities($entities, $type, $auth);
	}

	protected function artists($filter, $exact, $limit, $offset, $auth) {
		$artists = $this->findEntities($this->artistBusinessLayer, $filter, $exact, $limit, $offset);
		return $this->renderArtists($artists, $auth);
	}

	protected function artist($artistId, $auth) {
		$userId = $this->ampacheUser->getUserId();
		$artist = $this->artistBusinessLayer->find($artistId, $userId);
		return $this->renderArtists([$artist], $auth);
	}

	protected function artist_albums($artistId, $auth) {
		$userId = $this->ampacheUser->getUserId();
		$albums = $this->albumBusinessLayer->findAllByArtist($artistId, $userId);
		return $this->renderAlbums($albums, $auth);
	}

	protected function artist_songs($artistId, $auth) {
		$userId = $this->ampacheUser->getUserId();
		$tracks = $this->trackBusinessLayer->findAllByArtist($artistId, $userId);
		return $this->renderSongs($tracks, $auth);
	}

	protected function album_songs($albumId, $auth) {
		$userId = $this->ampacheUser->getUserId();

		$album = $this->albumBusinessLayer->find($albumId, $userId);
		$tracks = $this->trackBusinessLayer->findAllByAlbum($albumId, $userId);

		foreach ($tracks as &$track) {
			$track->setAlbum($album);
		}

		return $this->renderSongs($tracks, $auth);
	}

	protected function song($trackId, $auth) {
		$userId = $this->ampacheUser->getUserId();
		$track = $this->trackBusinessLayer->find($trackId, $userId);
		$trackInArray = [$track];
		return $this->renderSongs($trackInArray, $auth);
	}

	protected function songs($filter, $exact, $limit, $offset, $auth) {

		// optimized handling for fetching the whole library
		// note: the ordering of the songs differs between these two cases
		if (empty($filter) && !$limit && !$offset) {
			$tracks = $this->getAllTracks();
		}
		// general case
		else {
			$tracks = $this->findEntities($this->trackBusinessLayer, $filter, $exact, $limit, $offset);
		}

		return $this->renderSongs($tracks, $auth);
	}

	protected function search_songs($filter, $auth) {
		$userId = $this->ampacheUser->getUserId();
		$tracks = $this->trackBusinessLayer->findAllByNameRecursive($filter, $userId);
		return $this->renderSongs($tracks, $auth);
	}

	protected function albums($filter, $exact, $limit, $offset, $auth) {
		$albums = $this->findEntities($this->albumBusinessLayer, $filter, $exact, $limit, $offset);
		return $this->renderAlbums($albums, $auth);
	}

	protected function album($albumId, $auth) {
		$userId = $this->ampacheUser->getUserId();
		$album = $this->albumBusinessLayer->find($albumId, $userId);
		return $this->renderAlbums([$album], $auth);
	}

	protected function playlists($filter, $exact, $limit, $offset) {
		$userId = $this->ampacheUser->getUserId();
		$playlists = $this->findEntities($this->playlistBusinessLayer, $filter, $exact, $limit, $offset);

		// append "All tracks" if not searching by name, and it is not off-limit
		$allTracksIndex = $this->playlistBusinessLayer->count($userId);
		if (empty($filter) && self::indexIsWithinOffsetAndLimit($allTracksIndex, $offset, $limit)) {
			$playlists[] = new AmpacheController_AllTracksPlaylist($userId, $this->trackBusinessLayer, $this->l10n);
		}

		return $this->renderPlaylists($playlists);
	}

	protected function playlist($listId) {
		$userId = $this->ampacheUser->getUserId();
		if ($listId == self::ALL_TRACKS_PLAYLIST_ID) {
			$playlist = new AmpacheController_AllTracksPlaylist($userId, $this->trackBusinessLayer, $this->l10n);
		} else {
			$playlist = $this->playlistBusinessLayer->find($listId, $userId);
		}
		return $this->renderPlaylists([$playlist]);
	}

	protected function playlist_songs($listId, $limit, $offset, $auth) {
		if ($listId == self::ALL_TRACKS_PLAYLIST_ID) {
			$playlistTracks = $this->getAllTracks();
			$playlistTracks = \array_slice($playlistTracks, $offset, $limit);
		}
		else {
			$userId = $this->ampacheUser->getUserId();
			$playlistTracks = $this->playlistBusinessLayer->getPlaylistTracks($listId, $userId, $limit, $offset);
		}
		return $this->renderSongs($playlistTracks, $auth);
	}

	protected function playlist_generate($filter, $limit, $offset, $auth) {
		$mode = $this->request->getParam('mode', 'random');
		$album = $this->request->getParam('album');
		$artist = $this->request->getParam('artist');
		$flag = $this->request->getParam('flag');
		$format = $this->request->getParam('format', 'song');

		$tracks = $this->findEntities($this->trackBusinessLayer, $filter, false); // $limit and $offset are applied later

		// filter the found tracks according to the additional requirements
		if ($album !== null) {
			$tracks = \array_filter($tracks, function($track) use ($album) {
				return ($track->getAlbumId() == $album);
			});
		}
		if ($artist !== null) {
			$tracks = \array_filter($tracks, function($track) use ($artist) {
				return ($track->getArtistId() == $artist);
			});
		}
		if ($flag == 1) {
			$tracks = \array_filter($tracks, function($track) {
				return ($track->getStarred() !== null);
			});
		}
		// After filtering, there may be "holes" between the array indices. Reindex the array.
		$tracks = \array_values($tracks);

		if ($mode == 'random') {
			$userId = $this->ampacheUser->getUserId();
			$indices = $this->random->getIndices(\count($tracks), $offset, $limit, $userId, 'ampache_playlist_generate');
			$tracks = Util::arrayMultiGet($tracks, $indices);
		} else { // 'recent', 'forgotten', 'unplayed'
			throw new AmpacheException("Mode '$mode' is not supported", 400);
		}

		switch ($format) {
			case 'song':
				return $this->renderSongs($tracks, $auth);
			case 'index':
				return $this->renderSongsIndex($tracks);
			case 'id':
				return $this->renderEntityIds($tracks);
			default:
				throw new AmpacheException("Format '$format' is not supported", 400);
		}
	}

	protected function tags($filter, $exact, $limit, $offset) {
		$genres = $this->findEntities($this->genreBusinessLayer, $filter, $exact, $limit, $offset);
		return $this->renderTags($genres);
	}

	protected function tag($tagId) {
		$userId = $this->ampacheUser->getUserId();
		$genre = $this->genreBusinessLayer->find($tagId, $userId);
		return $this->renderTags([$genre]);
	}

	protected function tag_artists($genreId, $limit, $offset, $auth) {
		$userId = $this->ampacheUser->getUserId();
		$artists = $this->artistBusinessLayer->findAllByGenre($genreId, $userId, $limit, $offset);
		return $this->renderArtists($artists, $auth);
	}

	protected function tag_albums($genreId, $limit, $offset, $auth) {
		$userId = $this->ampacheUser->getUserId();
		$albums = $this->albumBusinessLayer->findAllByGenre($genreId, $userId, $limit, $offset);
		return $this->renderAlbums($albums, $auth);
	}

	protected function tag_songs($genreId, $limit, $offset, $auth) {
		$userId = $this->ampacheUser->getUserId();
		$tracks = $this->trackBusinessLayer->findAllByGenre($genreId, $userId, $limit, $offset);
		return $this->renderSongs($tracks, $auth);
	}

	protected function flag() {
		$type = $this->getRequiredParam('type');
		$id = $this->getRequiredParam('id');
		$flag = $this->getRequiredParam('flag');
		$flag = \filter_var($flag, FILTER_VALIDATE_BOOLEAN);

		if (!\in_array($type, ['song', 'album', 'artist'])) {
			throw new AmpacheException("Unsupported type $type", 400);
		}

		$userId = $this->ampacheUser->getUserId();
		$businessLayer = $this->getBusinessLayer($type);
		if ($flag) {
			$modifiedCount = $businessLayer->setStarred([$id], $userId);
			$message = "flag ADDED to $id";
		} else {
			$modifiedCount = $businessLayer->unsetStarred([$id], $userId);
			$message = "flag REMOVED from $id";
		}

		if ($modifiedCount > 0) {
			return $this->ampacheResponse(['success' => $message]);
		} else {
			throw new AmpacheException("The $type $id was not found", 400);
		}
	}

	protected function download($trackId) {
		$userId = $this->ampacheUser->getUserId();

		try {
			$track = $this->trackBusinessLayer->find($trackId, $userId);
		} catch (BusinessLayerException $e) {
			return new ErrorResponse(Http::STATUS_NOT_FOUND, $e->getMessage());
		}

		$files = $this->rootFolder->getUserFolder($userId)->getById($track->getFileId());

		if (\count($files) === 1) {
			return new FileResponse($files[0]);
		} else {
			return new ErrorResponse(Http::STATUS_NOT_FOUND);
		}
	}

	protected function stream($trackId, $offset) {
		// This is just a dummy implementation. We don't support transcoding or streaming
		// from a time offset.
		// All the other unsupported arguments are just ignored, but a request with an offset
		// is responded with an error. This is becuase the client would probably work in an
		// unexpected way if it thinks it's streaming from offset but actually it is streaming
		// from the beginning of the file. Returning an error gives the client a chance to fallback
		// to other methods of seeking.
		if ($offset !== null) {
			throw new AmpacheException('Streaming with time offset is not supported', 400);
		}

		return $this->download($trackId);
	}

	/***************************************************************
	 * API methods which are not part of the Ampache specification *
	 ***************************************************************/
	protected function _get_album_cover($albumId) {
		return $this->getCover($albumId, $this->albumBusinessLayer);
	}

	protected function _get_artist_cover($artistId) {
		return $this->getCover($artistId, $this->artistBusinessLayer);
	}


	/********************
	 * Helper functions *
	 ********************/

	private function getBusinessLayer($type) {
		switch ($type) {
			case 'song':		return $this->trackBusinessLayer;
			case 'album':		return $this->albumBusinessLayer;
			case 'artist':		return $this->artistBusinessLayer;
			case 'playlist':	return $this->playlistBusinessLayer;
			case 'tag':			return $this->genreBusinessLayer;
			default:			throw new AmpacheException("Unsupported type $type", 400);
		}
	}

	private function renderEntities($entities, $type, $auth) {
		switch ($type) {
			case 'song':		return $this->renderSongs($entities, $auth);
			case 'album':		return $this->renderAlbums($entities, $auth);
			case 'artist':		return $this->renderArtists($entities, $auth);
			case 'playlist':	return $this->renderPlaylists($entities);
			case 'tag':			return $this->renderTags($entities);
			default:			throw new AmpacheException("Unsupported type $type", 400);
		}
	}

	private function renderEntitiesIndex($entities, $type) {
		switch ($type) {
			case 'song':		return $this->renderSongsIndex($entities);
			case 'album':		return $this->renderAlbumsIndex($entities);
			case 'artist':		return $this->renderArtistsIndex($entities);
			case 'playlist':	return $this->renderPlaylistsIndex($entities);
			default:			throw new AmpacheException("Unsupported type $type", 400);
		}
	}

	private function getCover($entityId, BusinessLayer $businessLayer) {
		$userId = $this->ampacheUser->getUserId();
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$entity = $businessLayer->find($entityId, $userId);

		try {
			$coverData = $this->coverHelper->getCover($entity, $userId, $userFolder);
			if ($coverData !== null) {
				return new FileResponse($coverData);
			}
		} catch (BusinessLayerException $e) {
			return new ErrorResponse(Http::STATUS_NOT_FOUND, 'entity not found');
		}

		return new ErrorResponse(Http::STATUS_NOT_FOUND, 'entity has no cover');
	}

	private function checkHandshakeTimestamp($timestamp, $currentTime) {
		$providedTime = \intval($timestamp);

		if ($providedTime === 0) {
			throw new AmpacheException('Invalid Login - cannot parse time', 401);
		}
		if ($providedTime < ($currentTime - self::SESSION_EXPIRY_TIME)) {
			throw new AmpacheException('Invalid Login - session is outdated', 401);
		}
		// Allow the timestamp to be at maximum 10 minutes in the future. The client may use its
		// own system clock to generate the timestamp and that may differ from the server's time.
		if ($providedTime > $currentTime + 600) {
			throw new AmpacheException('Invalid Login - timestamp is in future', 401);
		}
	}

	private function checkHandshakeAuthentication($user, $timestamp, $auth) {
		$hashes = $this->ampacheUserMapper->getPasswordHashes($user);

		foreach ($hashes as $hash) {
			$expectedHash = \hash('sha256', $timestamp . $hash);

			if ($expectedHash === $auth) {
				return;
			}
		}

		throw new AmpacheException('Invalid Login - passphrase does not match', 401);
	}

	private function startNewSession($user, $expiryDate) {
		// this can cause collision, but it's just a temporary token
		$token = \md5(\uniqid(\rand(), true));

		// create new session
		$session = new AmpacheSession();
		$session->setUserId($user);
		$session->setToken($token);
		$session->setExpiry($expiryDate);

		// save session
		$this->ampacheSessionMapper->insert($session);

		return $token;
	}

	private function findEntities(BusinessLayer $businessLayer, $filter, $exact, $limit=null, $offset=null) {
		$userId = $this->ampacheUser->getUserId();

		if ($filter) {
			$fuzzy = !((boolean) $exact);
			return $businessLayer->findAllByName($filter, $userId, $fuzzy, $limit, $offset);
		} else {
			return $businessLayer->findAll($userId, SortBy::Name, $limit, $offset);
		}
	}

	/**
	 * Getting all tracks with this helper is more efficient than with `findEntities`
	 * followed by a call to `albumBusinessLayer->find(...)` on each track.
	 * This is because, under the hood, the albums are fetched with a single DB query
	 * instead of fetching each separately.
	 *
	 * The result set is ordered first by artist and then by song title.
	 */
	private function getAllTracks() {
		$userId = $this->ampacheUser->getUserId();
		$tracks = $this->library->getTracksAlbumsAndArtists($userId)['tracks'];
		\usort($tracks, ['\OCA\Music\Db\Track', 'compareArtistAndTitle']);
		foreach ($tracks as $index => &$track) {
			$track->setNumberOnPlaylist($index + 1);
		}
		return $tracks;
	}

	private function createAmpacheActionUrl($action, $id, $auth) {
		$api = $this->jsonMode ? 'music.ampache.jsonApi' : 'music.ampache.xmlApi';
		return $this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute($api))
				. "?action=$action&id=$id&auth=$auth";
	}

	private function createCoverUrl($entity, $auth) {
		if ($entity instanceof Album) {
			$type = 'album';
		} elseif ($entity instanceof Artist) {
			$type = 'artist';
		} else {
			throw new AmpacheException('unexpeted entity type for cover image', 500);
		}

		if ($entity->getCoverFileId()) {
			return $this->createAmpacheActionUrl("_get_{$type}_cover", $entity->getId(), $auth);
		} else {
			return '';
		}
	}

	/**
	 * Any non-integer values and integer value 0 are converted to null to
	 * indicate "no limit" or "no offset".
	 * @param string $value
	 * @return integer|null
	 */
	private static function validateLimitOrOffset($value) {
		if (\ctype_digit(\strval($value)) && $value !== 0) {
			return \intval($value);
		} else {
			return null;
		}
	}

	/**
	 * @param int $index
	 * @param int|null $offset
	 * @param int|null $limit
	 * @return boolean
	 */
	private static function indexIsWithinOffsetAndLimit($index, $offset, $limit) {
		$offset = \intval($offset); // missing offset is interpreted as 0-offset
		return ($limit === null) || ($index >= $offset && $index < $offset + $limit);
	}

	private function renderArtists($artists, $auth) {
		$userId = $this->ampacheUser->getUserId();
		$genreMap = Util::createIdLookupTable($this->genreBusinessLayer->findAll($userId));

		return $this->ampacheResponse([
			'artist' => \array_map(function($artist) use ($userId, $genreMap, $auth) {
				return [
					'id' => (string)$artist->getId(),
					'name' => $artist->getNameString($this->l10n),
					'albums' => $this->albumBusinessLayer->countByArtist($artist->getId()),
					'songs' => $this->trackBusinessLayer->countByArtist($artist->getId()),
					'art' => $this->createCoverUrl($artist, $auth),
					'rating' => 0,
					'preciserating' => 0,
					'tag' => \array_map(function($genreId) use ($genreMap) {
						return [
							'id' => (string)$genreId,
							'value' => $genreMap[$genreId]->getNameString($this->l10n),
							'count' => 1
						];
					}, $this->trackBusinessLayer->getGenresByArtistId($artist->getId(), $userId))
				];
			}, $artists)
		]);
	}

	private function renderAlbums($albums, $auth) {
		$userId = $this->ampacheUser->getUserId();

		$genreMap = Util::createIdLookupTable($this->genreBusinessLayer->findAll($userId));

		return $this->ampacheResponse([
			'album' => \array_map(function($album) use ($auth, $genreMap) {
				return [
					'id' => (string)$album->getId(),
					'name' => $album->getNameString($this->l10n),
					'artist' => [
						'id' => (string)$album->getAlbumArtistId(),
						'value' => $album->getAlbumArtistNameString($this->l10n)
					],
					'tracks' => $this->trackBusinessLayer->countByAlbum($album->getId()),
					'rating' => 0,
					'year' => $album->yearToAPI(),
					'art' => $this->createCoverUrl($album, $auth),
					'preciserating' => 0,
					'tag' => \array_map(function($genreId) use ($genreMap) {
						return [
							'id' => (string)$genreId,
							'value' => $genreMap[$genreId]->getNameString($this->l10n),
							'count' => 1
						];
					}, $album->getGenres())
				];
			}, $albums)
		]);
	}

	private function renderSongs($tracks, $auth) {
		return $this->ampacheResponse([
			'song' => \array_map(function($track) use ($auth) {
				$album = $track->getAlbum()
						?: $this->albumBusinessLayer->find($track->getAlbumId(), $this->ampacheUser->getUserId());

				$result = [
					'id' => (string)$track->getId(),
					'title' => $track->getTitle(),
					'name' => $track->getTitle(),
					'artist' => [
						'id' => (string)$track->getArtistId(),
						'value' => $track->getArtistNameString($this->l10n)
					],
					'albumartist' => [
						'id' => (string)$album->getAlbumArtistId(),
						'value' => $album->getAlbumArtistNameString($this->l10n)
					],
					'album' => [
						'id' => (string)$album->getId(),
						'value' => $album->getNameString($this->l10n)
					],
					'url' => $this->createAmpacheActionUrl('download', $track->getId(), $auth),
					'time' => $track->getLength(),
					'year' => $track->getYear(),
					'track' => $track->getAdjustedTrackNumber(),
					'bitrate' => $track->getBitrate(),
					'mime' => $track->getMimetype(),
					'size' => $track->getSize(),
					'art' => $this->createCoverUrl($album, $auth),
					'rating' => 0,
					'preciserating' => 0,
				];

				$genreId = $track->getGenreId();
				if ($genreId !== null) {
					$result['tag'] = [[
						'id' => (string)$genreId,
						'value' => $track->getGenreNameString($this->l10n),
						'count' => 1
					]];
				}
				return $result;
			}, $tracks)
		]);
	}

	private function renderPlaylists($playlists) {
		return $this->ampacheResponse([
			'playlist' => \array_map(function($playlist) {
				return [
					'id' => (string)$playlist->getId(),
					'name' => $playlist->getName(),
					'owner' => $this->ampacheUser->getUserId(),
					'items' => $playlist->getTrackCount(),
					'type' => 'Private'
				];
			}, $playlists)
		]);
	}

	private function renderTags($genres) {
		return $this->ampacheResponse([
			'tag' => \array_map(function($genre) {
				return [
					'id' => (string)$genre->getId(),
					'name' => $genre->getNameString($this->l10n),
					'albums' => $genre->getAlbumCount(),
					'artists' => $genre->getArtistCount(),
					'songs' => $genre->getTrackCount(),
					'videos' => 0,
					'playlists' => 0,
					'stream' => 0
				];
			}, $genres)
		]);
	}

	private function renderSongsIndex($tracks) {
		return $this->ampacheResponse([
			'song' => \array_map(function($track) {
				return [
					'id' => (string)$track->getId(),
					'title' => $track->getTitle(),
					'name' => $track->getTitle(),
					'artist' => [
						'id' => (string)$track->getArtistId(),
						'value' => $track->getArtistNameString($this->l10n)
					],
					'album' => [
						'id' => (string)$track->getAlbumId(),
						'value' => $track->getAlbumNameString($this->l10n)
					]
				];
			}, $tracks)
		]);
	}

	private function renderAlbumsIndex($albums) {
		return $this->ampacheResponse([
			'album' => \array_map(function($album) {
				return [
					'id' => (string)$album->getId(),
					'name' => $album->getNameString($this->l10n),
					'artist' => [
						'id' => (string)$album->getAlbumArtistId(),
						'value' => $album->getAlbumArtistNameString($this->l10n)
					]
				];
			}, $albums)
		]);
	}

	private function renderArtistsIndex($artists) {
		return $this->ampacheResponse([
			'artist' => \array_map(function($artist) {
				$userId = $this->ampacheUser->getUserId();
				$albums = $this->albumBusinessLayer->findAllByArtist($artist->getId(), $userId);

				return [
					'id' => (string)$artist->getId(),
					'name' => $artist->getNameString($this->l10n),
					'album' => \array_map(function($album) {
						return [
							'id' => (string)$album->getId(),
							'value' => $album->getNameString($this->l10n)
						];
					}, $albums)
				];
			}, $artists)
		]);
	}

	private function renderPlaylistsIndex($playlists) {
		return $this->ampacheResponse([
			'playlist' => \array_map(function($playlist) {
				return [
					'id' => (string)$playlist->getId(),
					'name' => $playlist->getName(),
					'playlisttrack' => $playlist->getTrackIdsAsArray()
				];
			}, $playlists)
		]);
	}

	private function renderEntityIds($entities) {
		return $this->ampacheResponse(['id' => Util::extractIds($entities)]);
	}

	/**
	 * Array is considered to be "indexed" if its first element has numerical key.
	 * Empty array is considered to be "indexed".
	 * @param array $array
	 */
	private static function arrayIsIndexed(array $array) {
		reset($array);
		return empty($array) || \is_int(key($array));
	}

	/**
	 * The JSON API has some asymmetries with the XML API. This function makes the needed
	 * translations for the result content before it is converted into JSON. 
	 * @param array $content
	 * @return array
	 */
	private static function prepareResultForJsonApi($content) {
		// In all responses returning an array of library entities, the root node is anonymous.
		// Unwrap the outermost array if it is an associative array with a single array-type value.
		if (\count($content) === 1 && !self::arrayIsIndexed($content)
				&& \is_array(\current($content)) && self::arrayIsIndexed(\current($content))) {
			$content = \array_pop($content);
		}

		// The key 'value' has a special meaning on XML responses, as it makes the corresponding value
		// to be treated as text content of the parent element. In the JSON API, these are mostly
		// substituted with property 'name', but error responses use the property 'message', instead.
		if (\array_key_exists('error', $content)) {
			$content = Util::convertArrayKeys($content, ['value' => 'message']);
		} else {
			$content = Util::convertArrayKeys($content, ['value' => 'name']);
		}
		return $content;
	}

	/**
	 * The XML API has some asymmetries with the JSON API. This function makes the needed
	 * translations for the result content before it is converted into XML. 
	 * @param array $content
	 * @return array
	 */
	private static function prepareResultForXmlApi($content) {
		\reset($content);
		$firstKey = \key($content);

		// all 'entity list' kind of responses shall have the (deprecated) total_count element
		if ($firstKey == 'song' || $firstKey == 'album' || $firstKey == 'artist'
				|| $firstKey == 'playlist' || $firstKey == 'tag') {
			$content = ['total_count' => \count($content[$firstKey])] + $content;
		}

		// for some bizarre reason, the 'id' arrays have 'index' attributes in the XML format
		if ($firstKey == 'id') {
			$content['id'] = \array_map(function($id, $index) {
				return ['index' => $index, 'value' => $id];
			}, $content['id'], \array_keys($content['id']));
		}

		return ['root' => $content];
	}

	private function getRequiredParam($paramName) {
		$param = $this->request->getParam($paramName);

		if ($param === null) {
			throw new AmpacheException("Required parameter '$paramName' missing", 400);
		}

		return $param;
	}
}

/**
 * Adapter class which acts like the Playlist class for the purpose of 
 * AmpacheController::renderPlaylists but contains all the track of the user. 
 */
class AmpacheController_AllTracksPlaylist {

	private $user;
	private $trackBusinessLayer;
	private $l10n;

	public function __construct($user, $trackBusinessLayer, $l10n) {
		$this->user = $user;
		$this->trackBusinessLayer = $trackBusinessLayer;
		$this->l10n = $l10n;
	}

	public function getId() {
		return AmpacheController::ALL_TRACKS_PLAYLIST_ID;
	}

	public function getName() {
		return $this->l10n->t('All tracks');
	}

	public function getTrackCount() {
		return $this->trackBusinessLayer->count($this->user);
	}
}
