<?php
/**
 * Circles - bring cloud-users closer
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@pontapreta.net>
 * @copyright 2017
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

namespace OCA\Circles\Service;


use Exception;
use OC\User\NoUserException;
use OCA\Circles\Db\CirclesRequest;
use OCA\Circles\Db\MembersRequest;
use OCA\Circles\Exceptions\CircleTypeNotValidException;
use OCA\Circles\Exceptions\EmailAccountInvalidFormatException;
use OCA\Circles\Exceptions\GroupDoesNotExistException;
use OCA\Circles\Exceptions\MemberAlreadyExistsException;
use OCA\Circles\Model\Circle;
use OCA\Circles\Model\Member;
use OCP\IL10N;
use OCP\IUserManager;

class MembersService {

	/** @var string */
	private $userId;

	/** @var IL10N */
	private $l10n;

	/** @var IUserManager */
	private $userManager;

	/** @var ConfigService */
	private $configService;

	/** @var CirclesRequest */
	private $circlesRequest;

	/** @var MembersRequest */
	private $membersRequest;

	/** @var EventsService */
	private $eventsService;

	/** @var MiscService */
	private $miscService;

	/**
	 * MembersService constructor.
	 *
	 * @param $userId
	 * @param IL10N $l10n
	 * @param IUserManager $userManager
	 * @param ConfigService $configService
	 * @param CirclesRequest $circlesRequest
	 * @param MembersRequest $membersRequest
	 * @param EventsService $eventsService
	 * @param MiscService $miscService
	 */
	public function __construct(
		$userId,
		IL10N $l10n,
		IUserManager $userManager,
		ConfigService $configService,
		CirclesRequest $circlesRequest,
		MembersRequest $membersRequest,
		EventsService $eventsService,
		MiscService $miscService
	) {
		$this->userId = $userId;
		$this->l10n = $l10n;
		$this->userManager = $userManager;
		$this->configService = $configService;
		$this->circlesRequest = $circlesRequest;
		$this->membersRequest = $membersRequest;
		$this->eventsService = $eventsService;
		$this->miscService = $miscService;
	}


	/**
	 * @param string $circleUniqueId
	 * @param $ident
	 * @param $type
	 *
	 * @return array
	 * @throws \Exception
	 */
	public function addMember($circleUniqueId, $ident, $type) {

		try {
			$circle = $this->circlesRequest->getCircle($circleUniqueId, $this->userId);
			$circle->getHigherViewer()
				   ->hasToBeModerator();

			if (!$this->addMemberMassively($circle, $type, $ident)) {
				$this->verifyIdentBasedOnItsType($ident, $type);

				$member = $this->membersRequest->getFreshNewMember($circleUniqueId, $ident, $type);
				$member->hasToBeInviteAble();

				$this->addMemberBasedOnItsType($circle, $member);

				$this->membersRequest->updateMember($member);
				$this->eventsService->onMemberNew($circle, $member);
			}
		} catch (\Exception $e) {
			throw $e;
		}

		return $this->membersRequest->getMembers(
			$circle->getUniqueId(), $circle->getHigherViewer()
		);
	}


	private function addMemberMassively(Circle $circle, $type, $ident) {

		if ($type === Member::TYPE_GROUP) {
			return $this->addGroupMembers($circle, $ident);
		}

		return false;
	}


	private function addMemberBasedOnItsType(Circle $circle, Member &$member) {
		$this->addLocalMember($circle, $member);
		$this->addEmailAddress($member);
		$this->addContact($member);
	}


	/**
	 * @param string $ident
	 * @param int $type
	 *
	 * @throws Exception
	 */
	private function verifyIdentBasedOnItsType(&$ident, $type) {
		try {
			$this->verifyIdentLocalMember($ident, $type);
			$this->verifyIdentContact($ident, $type);
		} catch (Exception $e) {
			throw $e;
		}
	}

	private function verifyIdentLocalMember(&$ident, $type) {
		if ($type !== Member::TYPE_USER) {
			return;
		}

		try {
			$ident = $this->miscService->getRealUserId($ident);
		} catch (NoUserException $e) {
			throw new NoUserException($this->l10n->t("This user does not exist"));
		}
	}


	private function verifyIdentContact(&$ident, $type) {
		if ($type !== Member::TYPE_CONTACT) {
			return;
		}

//		try {
//			$ident = $this->miscService->getRealUserId($ident);
//		} catch (NoUserException $e) {
//			throw new NoUserException($this->l10n->t("This user does not exist"));
//		}

		$ident = $this->userId . ':' . $ident;
	}


	/**
	 * @param Circle $circle
	 * @param Member $member
	 *
	 * @throws \Exception
	 */
	public function addLocalMember(Circle $circle, Member $member) {

		if ($member->getType() !== Member::TYPE_USER) {
			return;
		}

		$member->inviteToCircle($circle->getType());
	}


	/**
	 * @param Member $member
	 *
	 * @throws \Exception
	 */
	private function addEmailAddress(Member $member) {

		if ($member->getType() !== Member::TYPE_MAIL) {
			return;
		}

		if (!filter_var($member->getUserId(), FILTER_VALIDATE_EMAIL)) {
			throw new EmailAccountInvalidFormatException(
				$this->l10n->t('Email format is not valid')
			);
		}

		$member->addMemberToCircle();
	}


	/**
	 * @param Member $member
	 *
	 * @throws \Exception
	 */
	private function addContact(Member $member) {

		if ($member->getType() !== Member::TYPE_CONTACT) {
			return;
		}

		$member->addMemberToCircle();
	}


	/**
	 * @param Circle $circle
	 * @param string $groupId
	 *
	 * @return bool
	 * @throws \Exception
	 */
	private function addGroupMembers(Circle $circle, $groupId) {

		$group = \OC::$server->getGroupManager()
							 ->get($groupId);
		if ($group === null) {
			throw new GroupDoesNotExistException($this->l10n->t('This group does not exist'));
		}

		foreach ($group->getUsers() as $user) {
			try {
				$member =
					$this->membersRequest->getFreshNewMember(
						$circle->getUniqueId(), $user->getUID(), Member::TYPE_USER
					);
				$member->hasToBeInviteAble();

				$member->inviteToCircle($circle->getType());
				$this->membersRequest->updateMember($member);

				$this->eventsService->onMemberNew($circle, $member);
			} catch (MemberAlreadyExistsException $e) {
			} catch (\Exception $e) {
				throw $e;
			}
		}

		return true;
	}


	/**
	 * getMember();
	 *
	 * Will return any data of a user related to a circle (as a Member). User can be a 'non-member'
	 * Viewer needs to be at least Member of the Circle
	 *
	 * @param $circleId
	 * @param $userId
	 * @param $type
	 *
	 * @return Member
	 * @throws \Exception
	 */
	public function getMember($circleId, $userId, $type) {

		try {
			$this->circlesRequest->getCircle($circleId, $this->userId)
								 ->getHigherViewer()
								 ->hasToBeMember();

			$member = $this->membersRequest->forceGetMember($circleId, $userId, $type);
			$member->setNote('');

			return $member;
		} catch (\Exception $e) {
			throw $e;
		}
	}


	/**
	 * @param string $circleUniqueId
	 * @param string $name
	 * @param int $type
	 * @param int $level
	 *
	 * @return array
	 * @throws \Exception
	 */
	public function levelMember($circleUniqueId, $name, $type, $level) {

		$level = (int)$level;
		try {
			$circle = $this->circlesRequest->getCircle($circleUniqueId, $this->userId);
			if ($circle->getType() === Circle::CIRCLES_PERSONAL) {
				throw new CircleTypeNotValidException(
					$this->l10n->t('You cannot edit level in a personal circle')
				);
			}

			$member = $this->membersRequest->forceGetMember($circle->getUniqueId(), $name, $type);
			$member->levelHasToBeEditable();
			if ($member->getLevel() !== $level) {
				if ($level === Member::LEVEL_OWNER) {
					$this->switchOwner($circle, $member);
				} else {
					$this->editMemberLevel($circle, $member, $level);
				}

				$this->eventsService->onMemberLevel($circle, $member);
			}

			return $this->membersRequest->getMembers(
				$circle->getUniqueId(), $circle->getHigherViewer()
			);
		} catch (\Exception $e) {
			throw $e;
		}

	}


	/**
	 * @param Circle $circle
	 * @param Member $member
	 * @param $level
	 *
	 * @throws \Exception
	 */
	private function editMemberLevel(Circle $circle, Member &$member, $level) {
		try {
			$isMod = $circle->getHigherViewer();
			$isMod->hasToBeModerator();
			$isMod->hasToBeHigherLevel($level);

			$member->hasToBeMember();
			$member->cantBeOwner();
			$isMod->hasToBeHigherLevel($member->getLevel());

			$member->setLevel($level);
			$this->membersRequest->updateMember($member);
		} catch (\Exception $e) {
			throw $e;
		}

	}

	/**
	 * @param Circle $circle
	 * @param Member $member
	 *
	 * @throws \Exception
	 */
	private function switchOwner(Circle $circle, Member &$member) {
		try {
			$isMod = $circle->getHigherViewer();
			$isMod->hasToBeOwner();

			$member->hasToBeMember();
			$member->cantBeOwner();

			$member->setLevel(Member::LEVEL_OWNER);
			$this->membersRequest->updateMember($member);

			$isMod->setLevel(Member::LEVEL_ADMIN);
			$this->membersRequest->updateMember($isMod);

		} catch (\Exception $e) {
			throw $e;
		}
	}


	/**
	 * @param string $circleUniqueId
	 * @param string $name
	 * @param $type
	 *
	 * @return array
	 * @throws \Exception
	 */
	public function removeMember($circleUniqueId, $name, $type) {

		try {
			$circle = $this->circlesRequest->getCircle($circleUniqueId, $this->userId);
			$circle->getHigherViewer()
				   ->hasToBeModerator();

			$member = $this->membersRequest->forceGetMember($circleUniqueId, $name, $type);
			$member->hasToBeMemberOrAlmost();
			$member->cantBeOwner();

			$circle->getHigherViewer()
				   ->hasToBeHigherLevel($member->getLevel());
		} catch (\Exception $e) {
			throw $e;
		}

		$this->eventsService->onMemberLeaving($circle, $member);

		$member->setStatus(Member::STATUS_NONMEMBER);
		$member->setLevel(Member::LEVEL_NONE);
		$this->membersRequest->updateMember($member);

		return $this->membersRequest->getMembers(
			$circle->getUniqueId(), $circle->getHigherViewer()
		);
	}


	/**
	 * When a user is removed, remove him from all Circles
	 *
	 * @param $userId
	 */
	public function onUserRemoved($userId) {
		$this->membersRequest->removeAllFromUser($userId);
	}


}