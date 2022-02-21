<?php

namespace BM2\SiteBundle\Service;

use BM2\SiteBundle\Entity\Character;
use BM2\SiteBundle\Entity\Realm;
use BM2\SiteBundle\Entity\RealmPosition;
use BM2\SiteBundle\Entity\Settlement;
use BM2\SiteBundle\Entity\SettlementClaim;

use Doctrine\ORM\EntityManager;
use Doctrine\Common\Collections\ArrayCollection;


class Politics {

	protected $em;
	protected $history;

	public function __construct(EntityManager $em, History $history) {
		$this->em = $em;
		$this->history = $history;
	}


	public function isSuperior(Character $char, Character $lord) {
		// test if $lord is anywhere in the hierarchy above $char
		$next = $char;
		while ($next = $next->getLiege()) {
			if ($next == $lord) {
				return true;
			}
		}
		return false;
	}

	public function breakoath(Character $character) {
		$alleg = $character->findAllegiance();
		if (!$alleg instanceof RealmPosition) {
			$this->history->logEvent(
				$alleg,
				'politics.oath.broken',
				array('%link-character%'=>$character->getId()),
				History::MEDIUM, true
			);
		}
		if ($character->getLiege()) {
			$character->setLiege(null);
		}
		if ($character->getRealm()) {
			$character->setRealm(NULL);
		}
		if ($character->getLiegeLand()) {
			$character->setLiegeLand(NULL);
		}
		if ($character->getLiegePlace()) {
			$character->setLiegePlace(NULL);
		}
		if ($character->getLiegePosition()) {
			$character->setLiegePosition(NULL);
		}
	}

	public function disown(Character $character) {
		if ($character->getLiege()) {
			$this->history->logEvent(
				$character,
				'politics.oath.disowned',
				array('%link-character%'=>$character->getLiege()->getId()),
				History::MEDIUM, true
			);
			$this->history->logEvent(
				$character->findAllegiance(),
				'politics.oath.disowner',
				array('%link-character%'=>$character->getId()),
				History::MEDIUM, true
			);
			$character->setLiege(null);
			return true;
		}
		if ($character->getLiegeLand()) {
			$this->history->logEvent(
				$character,
				'politics.oath.landdisowned',
				array('%link-settlement%'=>$character->getLiegeLand()->getId()),
				History::MEDIUM, true
			);
			$this->history->logEvent(
				$character->findAllegiance(),
				'politics.oath.disowner',
				array('%link-character%'=>$character->getId()),
				History::MEDIUM, true
			);
			$character->setLiegeLand(null);
			return true;
		}
		if ($character->getLiegePlace()) {
			$this->history->logEvent(
				$character,
				'politics.oath.placedisowned',
				array('%link-place%'=>$character->getLiegePlace()->getId()),
				History::MEDIUM, true
			);
			$this->history->logEvent(
				$character->findAllegiance(),
				'politics.oath.disowner',
				array('%link-character%'=>$character->getId()),
				History::MEDIUM, true
			);
			$character->setLiegePlace(null);
			return true;
		}
		if ($character->getLiegePosition()) {
			$this->history->logEvent(
				$character,
				'politics.oath.positiondisowned',
				array('%link-place%'=>$character->getLiegePosition()->getId()),
				History::MEDIUM, true
			);
			$this->history->logEvent(
				$character->findAllegiance(),
				'politics.oath.disowner',
				array('%link-character%'=>$character->getId()),
				History::MEDIUM, true
			);
			$character->setLiegePosition(null);
			return true;
		}
		if ($character->getRealm()) {
			$this->history->logEvent(
				$character,
				'politics.oath.realmdisowned',
				array('%link-realm%'=>$character->getRealm()->getId()),
				History::MEDIUM, true
			);
			$this->history->logEvent(
				$character->findAllegiance(),
				'politics.oath.disowner',
				array('%link-character%'=>$character->getId()),
				History::LOW, true
			);
			$character->setRealm(null);
			return true;
		}
	}


	public function changeSettlementOwner(Settlement $settlement, Character $character=null, $reason=false) {
		$oldowner = $settlement->getOwner();
		if ($oldowner) {
			$oldowner->removeOwnedSettlement($settlement);
		}
		if ($character) {
			$character->addOwnedSettlement($settlement);
		}
		$settlement->setOwner($character);

		$occupantTakeOver = false;
		if ($reason == 'take' && $settlement->getOccupant()) {
			if ($settlement->getOccupant() == $character) {
				$occupantTakeOver = true;
				$this->endOccupation($settlement, 'take', true);
			} else {
				$this->endOccupation($settlement, 'take', false);
			}
		}

		// clean out claim if we have one
		if ($character) {
			if ($this->removeClaim($character, $settlement)) {
				// FIXME: would love to add achievement here, but politics is injected into
				//			 charactermanager, so it can't inject CM - circular injections)
				//$this->character_manager->addAchievement($character, 'claimspressed');
			}
		}

		// clean out permissions
		foreach ($settlement->getPermissions() as $perm) {
			$settlement->removePermission($perm);
			$this->em->remove($perm);
		}
		if (!$occupantTakeOver) {
			# endoccupation handles this on it's own.
			foreach ($settlement->getPermissions() as $perm) {
				$settlement->removePermission($perm);
				$this->em->remove($perm);
			}
		}

		// TODO: probably need to clean out some actions, too

		if ($reason) switch ($reason) {
			case 'take':
				if ($settlement->getOwner()) {
					$this->history->logEvent(
						$oldowner,
						'event.settlement.ownership.lost',
						array('%link-settlement%'=>$settlement->getId(), '%link-character%'=>$character->getId()),
						History::HIGH, true
					);
				}
				if ($settlement->getSteward() && $settlement->getSteward() != $character) {
					$this->history->logEvent(
						$settlement->getSteward(),
						'event.settlement.ownership.lost',
						array('%link-settlement%'=>$settlement->getId(), '%link-character%'=>$character->getId()),
						History::HIGH, true
					);
				}
				$settlement->setSteward(null);
				$this->history->logEvent(
					$character,
					'event.settlement.ownership.gained',
					array('%link-settlement%'=>$settlement->getId()),
					History::HIGH, true
				);
				// add a claim for the old owner -- FIXME: He should have ruled for some time before this becomes an enforceable claim
				// 										   or maybe more general change: all enforceable claims last only for (1x, 2x, 3x) as long as you had ruled?
				if ($oldowner && $oldowner->isAlive() && !$oldowner->getSlumbering()) {
					$this->addClaim($oldowner, $settlement, true, false);
				}
				break;
			case 'grant':
				$this->history->logEvent(
					$settlement,
					'event.settlement.granted',
					array('%link-character%'=>$character->getId()),
					History::HIGH, true
				);
				$this->history->logEvent(
					$oldowner,
					'resolution.grant.success',
					array('%link-settlement%'=>$settlement->getId(), '%link-character%'=>$character->getId()),
					History::LOW, true
				);
				$this->history->logEvent(
					$character,
					'event.character.wasgranted',
					array('%link-settlement%'=>$settlement->getId(), '%link-character%'=>$oldowner->getId()),
					History::HIGH, true
				);
				if ($settlement->getSteward() && $settlement->getSteward() != $character) {
					$this->history->logEvent(
						$settlement->getSteward(),
						'event.character.wasgranted',
						array('%link-settlement%'=>$settlement->getId(), '%link-character%'=>$oldowner->getId()),
						History::HIGH, true
					);
				}
				if ($settlement->getSteward() == $character) {
					$settlement->setSteward(null);
				}
				break;
			case 'grant_fief':
				$this->history->logEvent(
					$settlement,
					'event.settlement.granted2',
					array('%link-character%'=>$character->getId()),
					History::HIGH, true
				);
				$this->history->logEvent(
					$oldowner,
					'resolution.grant.success2',
					array('%link-settlement%'=>$settlement->getId(), '%link-character%'=>$character->getId()),
					History::LOW, true
				);
				$this->history->logEvent(
					$character,
					'event.character.wasgranted2',
					array('%link-settlement%'=>$settlement->getId(), '%link-character%'=>$oldowner->getId()),
					History::HIGH, true
				);
				if ($settlement->getSteward() && $settlement->getSteward() != $character) {
					$this->history->logEvent(
						$settlement->getSteward(),
						'event.character.wasgranted2',
						array('%link-settlement%'=>$settlement->getId(), '%link-character%'=>$oldowner->getId()),
						History::HIGH, true
					);
				}
				if ($settlement->getSteward() == $character) {
					$settlement->setSteward(null);
				}
				// add a claim for the old owner
				// FIXME: He should have ruled for some time before this becomes an enforceable claim
				// or maybe more general change: all enforceable claims last only for (1x, 2x, 3x) as long as you had ruled?
				// the real question is: how do we get how long he ruled?
				if ($oldowner && $oldowner->isAlive() && !$oldowner->getSlumbering()) {
					$this->addClaim($oldowner, $settlement, true, true);
				}
				break;
		}
	}

	public function addClaim(Character $character, Settlement $settlement, $enforceable=false, $priority=false) {
		foreach ($settlement->getClaims() as $claim) {
			if ($claim->getCharacter() == $character) {
				if ($enforceable) {
					$claim->setEnforceable(true);
				}
				if ($priority) {
					$claim->setPriority(true);
				}
				return;
			}
		}

		$claim = new SettlementClaim;
		$claim->setCharacter($character);
		$claim->setSettlement($settlement);
		$claim->setEnforceable($enforceable);
		$claim->setPriority($priority);
		$this->em->persist($claim);

		$character->addSettlementClaim($claim);
		$settlement->addClaim($claim);
	}

	public function removeClaim(Character $character, Settlement $settlement) {
		$result = false;
		foreach ($settlement->getClaims() as $claim) {
			if ($claim->getCharacter() == $character) {
				$claim->getSettlement()->removeClaim($claim);
				$claim->getCharacter()->removeSettlementClaim($claim);
				$this->em->remove($claim);
				$result = true;
			}
		}
		return $result;
	}

	public function changeSettlementRealm(Settlement $settlement, Realm $newrealm=null, $reason=false) {
		$oldrealm = $settlement->getRealm();

		if ($newrealm) {
			$newrealm->addSettlement($settlement);
		}
		if ($oldrealm) {
			$oldrealm->removeSettlement($settlement);
		}
		$settlement->setRealm($newrealm);

		// history events
		switch ($reason) {
			case 'change':	// settlement owner has decided to change
				if ($newrealm) {
					$this->history->logEvent(
						$settlement,
						'event.settlement.changed',
						array('%link-realm%'=>$newrealm->getId()),
						History::MEDIUM
					);
				}
				if ($oldrealm && $newrealm) {
					// TODO: different text when the new realm is related to the old realm (subrealm, parent realm, etc.)
					$this->history->logEvent(
						$oldrealm,
						'event.realm.lost2',
						array('%link-settlement%'=>$settlement->getId(), '%link-realm%'=>$newrealm->getId()),
						History::MEDIUM
					);
					$this->history->logEvent(
						$newrealm,
						'event.realm.gained2',
						array('%link-settlement%'=>$settlement->getId(), '%link-realm%'=>$oldrealm->getId()),
						History::MEDIUM
					);
				} else if ($oldrealm) {
					$this->history->logEvent(
						$oldrealm,
						'event.realm.lost',
						array('%link-settlement%'=>$settlement->getId()),
						History::MEDIUM
					);
				} else {
					$this->history->logEvent(
						$newrealm,
						'event.realm.gained',
						array('%link-settlement%'=>$settlement->getId()),
						History::MEDIUM
					);
				}
				break;

			case 'subrealm': // is part of a new subrealm being founded
				$this->history->logEvent(
					$settlement,
					'event.settlement.subrealm',
					array('%link-realm%'=>$newrealm->getId()),
					History::HIGH, true
				);
				break;

			case 'take': // has been taken via the take control action
				if ($newrealm!=null && $newrealm != $oldrealm) {
					// joining a different realm
					$this->history->logEvent(
						$settlement,
						'event.settlement.taken2',
						array('%link-character%'=>$settlement->getOwner()->getId(), '%link-realm%'=>$newrealm->getId()),
						History::MEDIUM
					);
					if ($oldrealm) {
						// TODO: different text when the new realm is related to the old realm (subrealm, parent realm, etc.)
						$this->history->logEvent(
							$newrealm,
							'event.realm.gained2',
							array('%link-settlement%'=>$settlement->getId(), '%link-realm%'=>$oldrealm->getId()),
							History::MEDIUM
						);
						$this->history->logEvent(
							$oldrealm,
							'event.realm.lost2',
							array('%link-settlement%'=>$settlement->getId(), '%link-realm%'=>$newrealm->getId()),
							History::MEDIUM
						);
					} else {
						$this->history->logEvent(
							$newrealm,
							'event.realm.gained',
							array('%link-settlement%'=>$settlement->getId()),
							History::MEDIUM
						);
					}
				} else {
					// no target realm or same realm
					$this->history->logEvent(
						$settlement,
						'event.settlement.taken',
						array('%link-character%'=>$settlement->getOwner()->getId()),
						History::MEDIUM
					);
					if ($oldrealm && $newrealm==null) {
						$this->history->logEvent(
							$oldrealm,
							'event.realm.lost',
							array('%link-settlement%'=>$settlement->getId()),
							History::MEDIUM
						);
					}
				}
				break;
			case 'fail': // my realm has failed
				if ($newrealm) {
					$this->history->logEvent(
						$settlement,
						'event.settlement.realmfail2',
						array("%link-realm-1%"=>$newrealm->getId(), "%link-realm-2%"=>$oldrealm->getId()),
						History::HIGH, true
					);
					// TODO: message for new realm
				} else {
					$this->history->logEvent(
						$settlement,
						'event.settlement.realmfail',
						array("%link-realm%"=>$oldrealm->getId()),
						History::HIGH, true
					);
				}
				break;
			case 'update': // hierarchy update on new realm creation
				$this->history->logEvent(
					$settlement,
					'event.settlement.realm',
					array('%link-realm%'=>$newrealm->getId()),
					History::HIGH, true
				);
				break;
			case 'grant': // granted without realm
				$this->history->logEvent(
					$oldrealm,
					'event.realm.lost',
					array('%link-settlement%'=>$settlement->getId()),
					History::MEDIUM
				);
				break;
			default:
				// error, this should never happen
		} /* end switch */

		// wars
		$this->updateWarTargets($settlement, $oldrealm, $newrealm);
	}

	public function changeSettlementOccupier(Character $char, Settlement $settlement, Realm $realm = null) {
		$new = false;
		$old = null;
		if (!$settlement->getOccupier()) {
			$new = true;
		} else {
			$old = $settlement->getOccupier();
		}
		$settlement->setOccupant($char);
		if ($realm) {
			$settlement->setOccupier($realm);
		}
		if ($old || $realm) {
			$wars = $this->updateWarTargets($settlement, $old, $realm);
		}
		if ($realm) {
			if ($new) {
				$this->history->logEvent(
					$settlement,
					'event.settlement.occupied',
					array("%link-realm%"=>$realm->getId(), "%link-character%"=>$char->getId()),
					History::HIGH, true
				);
			} else {
				$this->history->logEvent(
					$settlement,
					'event.settlement.occupied2',
					array("%link-realm%"=>$realm->getId(), "%link-character%"=>$char->getId()),
					History::MEDIUM, true
				);
			}
		} else {
			if ($new) {
				$this->history->logEvent(
					$settlement,
					'event.settlement.occupied3',
					array("%link-character%"=>$char->getId()),
					History::HIGH, true
				);
			} else {
				$this->history->logEvent(
					$settlement,
					'event.settlement.occupied4',
					array("%link-character%"=>$char->getId()),
					History::MEDIUM, true
				);
			}
		}
	}

	public function changePlaceOccupier(Character $char, Place $place, Realm $realm) {
		$new = false;
		$old = null;
		if (!$place->getOccupier()) {
			$new = true;
		} else {
			$old = $place->getOccupier();
		}
		$place->setOccupier($realm);
		$place->setOccupant($char);
		if ($new) {
			$this->history->logEvent(
				$place,
				'event.place.occupied',
				array("%link-realm%"=>$realm->getId(), "%link-character%"=>$char->getId()),
				History::HIGH, true
			);
		} else {
			$this->history->logEvent(
				$place,
				'event.place.occupied2',
				array("%link-realm%"=>$realm->getId(), "%link-character%"=>$char->getId()),
				History::MEDIUM, true
			);
		}
	}

	public function endOccupation($target, $why = null, $occupantTakeOver = false) {
		$occupier = $target->getOccupier();
		$occupant = $target->getOccupant();
		$target->setOccupant(null);
		$target->setOccupier(null);
		if ($target instanceof Settlement) {
			$type = 'Settlement';
			$event = 'settlement';
			$warTargets = $target->getWarTargets();
			if ($warTargets && !$occupantTakeOver) {
				foreach ($warTargets as $warTarget) {
					if ($warTarget->getTakenCurrently()) {
						$warTarget->setTakenCurrently(false);
					}
				}
			}
		} else {
			$type = 'Place';
			$event = 'place';
		}
		if (!$occupantTakeOver) {
			foreach ($target->getOccupationPermissions() as $perm) {
				$this->em->remove($perm);
			}
		} else {
			foreach ($target->getPermissions() as $perm) {
				$this->em->remove($perm);
			}
			foreach ($target->getOccupationPermissions() as $perm) {
				$perm->set.$type($perm->getOccupied.$type());
				$perm->setOccupied.$type(null);
			}
		}
		if ($occupier) {
			if ($why == 'manual') {
				$this->history->logEvent(
					$target,
					'event.'.$event.'.endoccupation.manual',
					array("%link-realm%"=>$occupier->getId(), "%link-character%"=>$occupant->getId()),
					History::HIGH, true
				);
			} elseif ($why =='take') {
				if ($occupantTakeOver) {
					$this->history->logEvent(
						$target,
						'event.'.$event.'.endoccupation.take',
						array("%link-character%"=>$occupant->getId()),
						History::HIGH, true
					);
				} else {
					$this->history->logEvent(
						$target,
						'event.'.$event.'.endoccupation.othertake',
						array("%link-character%"=>$occupant->getId()),
						History::HIGH, true
					);
				}
			} else {
				$this->history->logEvent(
					$target,
					'event.'.$event.'.endoccupation.warended',
					array("%link-realm%"=>$occupier->getId(), "%link-character%"=>$occupant->getId()),
					History::HIGH, true
				);
			}
		} else {
			if ($why == 'manual') {
				$this->history->logEvent(
					$target,
					'event.'.$event.'.endoccupation.manual2',
					array("%link-character%"=>$occupant->getId()),
					History::HIGH, true
				);
			} elseif ($why =='take') {
				if ($occupantTakeOver) {
					$this->history->logEvent(
						$target,
						'event.'.$event.'.endoccupation.take',
						array("%link-character%"=>$occupant->getId()),
						History::HIGH, true
					);
				} else {
					$this->history->logEvent(
						$target,
						'event.'.$event.'.endoccupation.othertake',
						array("%link-character%"=>$occupant->getId()),
						History::HIGH, true
					);
				}
			}
		}

	}

	public function updateWarTargets(Settlement $settlement, Realm $oldRealm = null, Realm $newRealm = null) {
		$wars = [];
		foreach ($settlement->getWarTargets() as $target) {
			$old = false; $new = false;
			// FIXME: This doesn't work if oldrealm and newrealm are in the same hierarchy!
			if ($oldRealm && $oldRealm->findAllSuperiors(true)->contains($target->getWar()->getRealm())) {
				$old = true;
			}
			if ($newRealm && $newRealm->findAllSuperiors(true)->contains($target->getWar()->getRealm())) {
				$new = true;
			}
			if ($old != $new) {
				if ($old) {
					$target->setTakenCurrently(false);
				} else {
					$target->setTakenEver(true)->setTakenCurrently(true);
				}
				if (!in_array($target->getWar(), $wars)) {
					$wars[] = $target->getWar();
				}
			}
		}
		return $wars;
	}
}
