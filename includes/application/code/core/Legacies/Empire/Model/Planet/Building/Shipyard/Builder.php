<?php

class Legacies_Empire_Model_Planet_Building_Shipyard_Builder
    extends Legacies_Empire_Model_BuilderAbstract
{
    /**
     * @var int
     */
    protected $_maxLength = 0;

    public function init()
    {
        $this->_unserializeQueue($this->_currentPlanet->getData('b_hangar_id'));
    }

    /**
     * @param int $buildingId
     * @param int $qty
     * @param int $time
     */
    protected function _initItem(Array $params)
    {
        if (!isset($params['ship_id']) || !isset($params['qty'])) {
            return null;
        }

        $shipId = $params['ship_id'];
        $qty = $params['qty'];
        if (!isset($params['created_at'])) {
            $createdAt = time();
        } else {
            $createdAt = $params['created_at'];
        }
        if (!isset($params['updated_at'])) {
            $updatedAt = $createdAt;
        } else {
            $updatedAt = $params['updated_at'];
        }

        return new Legacies_Empire_Model_Planet_Building_Shipyard_Item(array(
            'ship_id'    => $shipId,
            'qty'        => $qty,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt
            ));
    }

    /**
     * Check if a ship or defense type is actually buildable on the current
     * planet, depending on the technology and buildings requirements.
     *
     * @param int $shipId
     * @return bool
     */
    public function checkAvailability($shipId)
    {
        $types = Legacies_Empire_Model_Game_Types::getSingleton();

        if (!$types->is($shipId, Legacies_Empire::TYPE_SHIP) && !$types->is($shipId, Legacies_Empire::TYPE_DEFENSE)) {
            return false;
        }

        parent::checkAvailability($shipId);

        return true;
    }

    /**
     * Returns the maximum quantity of elements that are possible to build on
     * the current planet.
     *
     * @param int $shipId
     * @return int|string
     */
    public function getMaximumBuildableElementsCount($shipId)
    {
        $prices = Legacies_Empire_Model_Game_Prices::getSingleton();
        $fields = Legacies_Empire_Model_Game_FieldsAlias::getSingleton();
        $resources = Legacies_Empire_Model_Game_Resources::getSingleton();

        $qty = 0;
        foreach ($resources as $resourceId => $_) {
            if (isset($prices[$shipId]) && isset($prices[$shipId][$resourceId]) && Math::comp($prices[$shipId][$resourceId], 0) > 0) {
                $maxQty = Math::floor(Math::div($this->_currentPlanet->getData($resourceId), $prices[$shipId][$resourceId]));

                if ($maxQty == 0) {
                    return 0;
                }

                if ($qty == 0 || Math::comp($maxQty, $qty) < 0) {
                    $qty = $maxQty;
                }
            }
        }

        if ($qty == 0) {
            return 0;
        }

        $limitedElementsQty = array(
            Legacies_Empire::ID_DEFENSE_SMALL_SHIELD_DOME      => array(
                'current'   => $this->_currentPlanet[$fields[Legacies_Empire::ID_DEFENSE_SMALL_SHIELD_DOME]],
                'requested' => $this->_currentPlanet[$fields[Legacies_Empire::ID_DEFENSE_SMALL_SHIELD_DOME]],
                'limit'     => 1
                ),
            Legacies_Empire::ID_DEFENSE_LARGE_SHIELD_DOME      => array(
                'current'   => $this->_currentPlanet[$fields[Legacies_Empire::ID_DEFENSE_LARGE_SHIELD_DOME]],
                'requested' => $this->_currentPlanet[$fields[Legacies_Empire::ID_DEFENSE_LARGE_SHIELD_DOME]],
                'limit'     => 1
                ),
            Legacies_Empire::ID_SPECIAL_ANTIBALLISTIC_MISSILE  => array(
                'current'   => $this->_currentPlanet[$fields[Legacies_Empire::ID_SPECIAL_ANTIBALLISTIC_MISSILE]],
                'requested' => $this->_currentPlanet[$fields[Legacies_Empire::ID_SPECIAL_ANTIBALLISTIC_MISSILE]],
                'limit'     => $this->_currentPlanet[$fields[Legacies_Empire::ID_BUILDING_MISSILE_SILO]] * 10
                ),
            Legacies_Empire::ID_SPECIAL_INTERPLANETARY_MISSILE => array(
                'current'   => $this->_currentPlanet[$fields[Legacies_Empire::ID_SPECIAL_INTERPLANETARY_MISSILE]],
                'requested' => $this->_currentPlanet[$fields[Legacies_Empire::ID_SPECIAL_INTERPLANETARY_MISSILE]],
                'limit'     => $this->_currentPlanet[$fields[Legacies_Empire::ID_BUILDING_MISSILE_SILO]] * 5
                )
            );

        if (in_array($shipId, array_keys($limitedElementsQty))) {
            foreach ($this->getQueue() as $element) {
                if ($element['ship_id'] != $shipId) {
                    continue;
                }

                $limitedElementsQty[$shipId]['requested'] = Math::add($limitedElementsQty[$shipId]['requested'], $element['qty']);
                if (Math::comp($limitedElementsQty[$shipId]['requested'], $limitedElementsQty[$shipId]['limit']) >= 0) {
                    return 0;
                }
            }
            if (Math::comp($limitedElementsQty[$shipId]['current'], $limitedElementsQty[$shipId]['limit']) >= 0) {
                return 0;
            }
            if (Math::comp($qty, $limitedElementsQty[$shipId]['limit']) >= 0) {
                return $limitedElementsQty[$shipId]['limit'];
            }
        }

        return $qty;
    }

    /**
     * Returns the time needed to build $qty of $shipId
     *
     * @param int $shipId
     * @param int $qty
     */
    public function getBuildingTime($shipId, $qty)
    {
        $prices = Legacies_Empire_Model_Game_Prices::getSingleton();
        $gameConfig = Legacies_Core_Model_Config::getSingleton();

        Math::setPrecision(50);
        $buildingTime = Math::mul($prices[$shipId][Legacies_Empire::BASE_BUILDING_TIME], $qty);

        $speedFactor = $gameConfig->getData('game_speed');
        $baseTime = Math::div($buildingTime, $speedFactor);

        Math::setPrecision();

        $event = Legacies::dispatchEvent('planet.shipyard.building-time', array(
            'time'        => $baseTime,
            'base_time'   => $baseTime,
            'planet'      => $this->_currentPlanet,
            'user'        => $this->_currentUser,
            'ship_id'     => $shipId,
            'qty'         => $qty
            ));

        return $event->getData('time');
    }

    /**
     * (non-PHPdoc)
     * @see Legacies_Empire_Model_BuilderAbstract::getResourcesNeeded()
     */
    public function getResourcesNeeded($shipId, $qty)
    {
        $prices = Legacies_Empire_Model_Game_Prices::getSingleton();
        $resources = Legacies_Empire_Model_Game_Resources::getSingleton();

        if (!isset($prices[$shipId])) {
            return array();
        }
        $resourcesNeeded = array();
        foreach ($resources as $resourceId => $resourceConfig) {
            if (!isset($prices[$shipId][$resourceId])) {
                continue;
            }
            if (Math::isPositive($prices[$shipId][$resourceId])) {
                $resourcesNeeded[$resourceId] = Math::mul($prices[$shipId][$resourceId], $qty);
            }
        }

        return $resourcesNeeded;
    }

    /**
     * Returns the quantity set in parameter or the maximum buildable elements
     * if the quantity requested exeeds this number.
     *
     * @param int $shipId
     * @param int|string $qty
     * @return int|stirng
     */
    protected function _checkMaximumQuantity($shipId, $qty)
    {
        return Math::min($qty, $this->getMaximumBuildableElementsCount($shipId));
    }

    /**
     * Update the contruction queue.
     *
     * @return Legacies_Empire_Model_Planet_Building_Shipyard
     */
    public function updateQueue($time)
    {
        $fields = Legacies_Empire_Model_Game_FieldsAlias::getSingleton();

        $elapsedTime = $time - $this->_currentPlanet->getData('b_hangar');

        foreach ($this->getQueue() as $element) {
            $shipId = $element->getData('ship_id');
            $qty = $element->getData('qty');
            $buildTime = $this->getBuildingTime($shipId, $qty);

            if ($elapsedTime >= $buildTime) {
                $this->_currentPlanet[$fields[$shipId]] = Math::add($this->_currentPlanet[$fields[$shipId]], $qty);
                $elapsedTime -= $buildTime;
                $this->dequeue($element);
                continue;
            }

            $timeRatio = $elapsedTime / $buildTime;
            $itemsBuilt = Math::mul($timeRatio, $qty);

            $element->setData('updated_at', $time);
            $element->setData('qty', Math::sub($qty, $itemsBuilt));
            $this->_currentPlanet->setData($fields[$shipId], Math::add($this->_currentPlanet->getData($fields[$shipId]), $itemsBuilt));
            break;
        }

        $this->_currentPlanet->setData('b_hangar_id', $this->serialize());
        $this->_currentPlanet->setData('b_hangar', $time);

        return $this;
    }

    /**
     * Append items to build to the construction list
     *
     * @param int $shipId
     * @param int|string $qty
     * @return Legacies_Empire_Model_Planet_Building_Shipyard
     */
    public function appendQueue($shipId, $qty, $time)
    {
        if ($this->_maxLength > 0 && $this->count() >= $this->_maxLength) {
            return $this;
        }

        if (!Math::isPositive($qty)) {
            return $this;
        }

        $types = Legacies_Empire_Model_Game_Types::getSingleton();
        if (!$types->is($shipId, Legacies_Empire::TYPE_SHIP) && !$types->is($shipId, Legacies_Empire::TYPE_DEFENSE)) {
            return $this;
        }

        if (!$this->checkAvailability($shipId)) {
            return $this;
        }

        if (MAX_FLEET_OR_DEFS_PER_ROW > 0) {
            $qty = Math::min($this->_checkMaximumQuantity($shipId, $qty), MAX_FLEET_OR_DEFS_PER_ROW);
        } else {
            $qty = $this->_checkMaximumQuantity($shipId, $qty);
        }

        if (!Math::isPositive($qty)) {
            return $this;
        }

        $resourcesNeeded = $this->getResourcesNeeded($shipId, $qty);
        $remainingAmounts = $this->_calculateResourceRemainingAmounts($resourcesNeeded);
        if ($remainingAmounts === false) {
            return $this;
        }

        $this->enqueue($shipId, $qty, $time);
        $this->_currentPlanet->setData('b_hangar_id', $this->serialize());

        foreach ($remainingAmounts as $resourceId => $resourceAmount) {
            $this->_currentPlanet[$resourceId] = $resourceAmount;
        }

        return $this;
    }
}