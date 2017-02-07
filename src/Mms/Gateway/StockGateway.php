<?php
/**
 * @category Magento
 * @package Magento\Gateway
 * @author Andreas Gerhards <andreas@lero9.co.nz>
 * @copyright Copyright (c) 2016 LERO9 Ltd.
 * @license Commercial - All Rights Reserved
 */

namespace Mms\Gateway;

use Log\Service\LogService;
use Magelink\Exception\MagelinkException;


class StockGateway extends AbstractGateway
{

    const GATEWAY_ENTITY = 'stockitem';
    const GATEWAY_ENTITY_CODE = 'si';

    const MMS_BUNDLE_SKU_SEPARATOR = '**';

    /**
     * Initialize the gateway and perform any setup actions required.
     * @param string $entityType
     * @return bool $success
     * @throws MagelinkException
     */
    protected function _init($entityType)
    {
        $success = parent::_init($entityType);

        if ($entityType != 'stockitem') {
            throw new \Magelink\Exception\MagelinkException('Invalid entity type for this gateway');
            $success = FALSE;
        }

        return $success;
    }

    /**
     * Retrieve and action all updated records (either from polling, pushed data, or other sources).
     * @return bool|NULL $success
     */
    public function retrieveEntities()
    {
        return NULL;
    }

    /**
     * Write out all the updates to the given entity.
     * @param \Entity\Entity $stockitem
     * @param string[] $attributes
     * @param int $type
     * @throws MagelinkException
     */
    public function writeUpdates(\Entity\Entity $stockitem, $attributes, $type = \Entity\Update::TYPE_UPDATE)
    {
        $nodeId = $this->_node->getNodeId();
        $product = $stockitem->getParent();
        $uniqueId = $stockitem->getUniqueId();
        $localId = $this->_entityService->getLocalId($nodeId, $stockitem);

        $isMmsEntity = ProductGateway::isMmsEntity($product);
        $mmsQuantities = ProductGateway::getTmallQuantities($product);

        $logCodePrefix = 'mms_si';
        $logMessagePrefix = 'Stock update for '.$uniqueId.' ';
        $logMessage = '';
        $logData = array('node'=>$nodeId, 'id'=>$stockitem->getEntityId(), 'unique'=>$uniqueId, 'local'=>$localId);

        if (!$isMmsEntity) {
            $success = NULL;
            $logLevel = LogService::LEVEL_WARN;
            $logCode = $logCodePrefix.'_nmms';
            $logMessage = $logMessagePrefix.'was skipped. This item is not defined as a MMS product.';
        }elseif (!in_array('available', $attributes)) {
            $success = NULL;
            $logLevel = LogService::LEVEL_WARN;
            $logCode = $logCodePrefix.'_skip';
            $logMessage .= $logMessagePrefix.'was skipped. Availabilty is not set.';
            $logData['attribute'] = implode(', ', $attributes);
        }else{
            $localId = $this->_entityService->getLocalId($nodeId, $stockitem);
            if ($this->rest) {
                $remainingQuantities = count($mmsQuantities);
                foreach ($mmsQuantities as $multiplier) {
                    try{
                        $sku = $uniqueId.($multiplier == 1 ? '' : self::MMS_BUNDLE_SKU_SEPARATOR.$multiplier);
                        $available = $logData['available'] = $stockitem->getData('available');

                        if ($localId && $multiplier == 1) {
                            try{
                                $newStock = $logData['new stock'] = $this->rest->setStock($stockitem, $available);
                            }catch(\Exception $exception) {}

                            if (!isset($newStock) || ($newStock != $available)) {
                                $message = $logMessagePrefix.' via local id failed due to a API problem.';
                                unset($newStock);
                            }
                        }elseif (!$localId) {
                            $message = $logMessagePrefix.'could not be executed due to a missing local id.';
                        }else{
                            $logMessage .= '(call by sku only) ';
                        }

                        if (!isset($newStock)) {
                            if (isset($message)) {
                                $this->getServiceLocator()->get('logService')
                                    ->log(LogService::LEVEL_WARN, $logCodePrefix.'_callbysku', trim($message), $logData);
                            }
                            $newStock = $logData['new stock'] = $this->rest->setStockBySku($sku, $available);
                        }

                        $success = ($newStock == $available);
                        if ($success) {
                            $logLevel = LogService::LEVEL_INFO;
                            $logCode = $logCodePrefix.'_suc';
                            $logMessage = $logMessagePrefix.'was successful.';
                        }elseif ($localId) {
                            $logLevel = LogService::LEVEL_ERROR;
                            $logCode = $logCodePrefix.'_fail';
                            $logMessage = $logMessagePrefix.'failed due to a API problem.';
                        }else{
                            $logLevel = LogService::LEVEL_WARN;
                            $logCode = $logCodePrefix.'_ignore';
                            $logMessage .= 'failed due to a API problem and was therefore ignored (no local id).';
                        }
                        unset($message, $newStock);
                    }catch(\Exception $exception) {
                        $success = FALSE;
                        $logLevel = LogService::LEVEL_ERROR;
                        $logCode = $logMessagePrefix.'_ex';
                        $logMessage .= 'failed with an exception: '.$exception->getMessage();
                    }

                    if (--$remainingQuantities > 0) {
                        $this->getServiceLocator()->get('logService')->log($logLevel, $logCode, $logMessage, $logData);
                    }
                }
            }elseif (isset($localId)) {
                $success = FALSE;
                $logLevel = LogService::LEVEL_ERROR;
                $logCode = $logMessagePrefix.'_norest';
                $logMessage .= 'could not be executed due to a problem with the REST initialisation.';
            }else{
                $success = FALSE;
                $logLevel = LogService::LEVEL_ERROR;
                $logCode = $logMessagePrefix.'_none';
                $logMessage .= 'could not be processed. Neither local id nor REST was available.';
            }
        }

        $this->getServiceLocator()->get('logService')
            ->log($logLevel, $logCode, $logMessage, $logData);

        return $success;
    }

    /**
     * Write out the given action.
     * @param \Entity\Action $action
     * @throws MagelinkException
     */
    public function writeAction(\Entity\Action $action)
    {
        return NULL;
    }

}
