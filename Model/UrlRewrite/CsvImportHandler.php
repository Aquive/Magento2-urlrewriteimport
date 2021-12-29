<?php
/**
 * Url Rewrite Import admin controller
 *
 * @category    Jworks
 * @package     Jworks_UrlRewriteImport
 * @author Jitheesh V O <jitheeshvo@gmail.com>
 * @copyright Copyright (c) 2017 Jworks Digital ()
 */
namespace Jworks\UrlRewriteImport\Model\UrlRewrite;

use Magento\Framework\App\ResourceConnection;

/**
 * URL rewrite CSV Import Handler
 */
class CsvImportHandler
{
    /**
     * DB connection
     *
     * @var \Magento\Framework\DB\Adapter\AdapterInterface
     */
    protected $_connection;

    /**
     * Collection of publicly available stores
     *
     * @var \Magento\Store\Model\ResourceModel\Store\Collection
     */
    protected $_publicStores;

    /**
     * CSV Processor
     *
     * @var \Magento\Framework\File\Csv
     */
    protected $csvProcessor;

    /**
     * Customer entity DB table name.
     *
     * @var string
     */
    protected $_entityTable;
    /**
     * @var array
     */
    protected $_rewriteFields;

    /**
     * @var \Magento\UrlRewrite\Model\UrlRewrite
     */
    protected $_urlModel;

    /**
     * @var array
     */
    protected $errors = [];
    /**
     * @var array
     */
    protected $success = [];

    /**
     * Redirect type
     */
    const ENTITY_TYPE_CUSTOM = 'custom';

    /**
     * CsvImportHandler constructor.
     * @param \Magento\Store\Model\ResourceModel\Store\Collection $storeCollection
     * @param \Magento\Framework\File\Csv $csvProcessor
     * @param ResourceConnection $resource
     * @param \Magento\UrlRewrite\Model\UrlRewriteFactory $urlRewriteFactory
     * @param array $data
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function __construct(
        \Magento\Store\Model\ResourceModel\Store\Collection $storeCollection,
        \Magento\Framework\File\Csv $csvProcessor,
        ResourceConnection $resource,
        \Magento\UrlRewrite\Model\UrlRewriteFactory $urlRewriteFactory,
        array $data = []
    )
    {
        // prevent admin store from loading
        $this->_publicStores = $this->_populateStoreData($storeCollection->getData());
        $this->csvProcessor = $csvProcessor;
        $this->_connection =
            isset($data['connection']) ?
                $data['connection'] :
                $resource->getConnection();
        $this->_urlModel = $urlRewriteFactory->create();
        $urlResource = $this->_urlModel->getResource(); /** @var $urlResource \Magento\UrlRewrite\Model\ResourceModel\UrlRewrite */
        $this->_entityTable = $urlResource->getMainTable();
        $this->_rewriteFields = ['request_path', 'target_path', 'redirect_type', 'store_id', 'entity_type'];
    }

    /**
     * @param $data
     * @return array
     */
    protected function _populateStoreData($data)
    {
        $stores = [];
        foreach ($data as $store) {
            $stores[$store['code']] = $store['store_id'];
        }
        return $stores;
    }

    /**
     * @param $file
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function importFromCsvFile($file)
    {
        if (!isset($file['tmp_name'])) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Invalid file upload attempt.'));
        }
        $urlRawData = $this->csvProcessor->getData($file['tmp_name']);
        $urlRawData = $this->_prepareData($urlRawData);

        foreach ($urlRawData as $rowIndex => $dataRow) {
            // Offset of 2 for line. One for the header line. One to account for array starting at 0 instead of 1.
            $lineNumber = $rowIndex + 2;

            // Catch errors on validation and log it, continue on next CSV line
            try {
                $this->_validateRewrite($dataRow);
            } catch(\Throwable $exception) {
                $this->errors['Line: ' . $lineNumber] = $exception->getMessage();
                continue;
            }

            // Catch errors on database insert and log it, continue on next CSV line
            try {
                $urlData = $this->parse($dataRow);
                $this->_importUrl($urlData);
                $this->success['Line: ' . $lineNumber] = 'Successfully imported';
            } catch (\Exception $exception) {
                $this->errors['Line: ' . $lineNumber] = $exception->getMessage();
                continue;
            }
        }

        return [
            'errors' => $this->errors,
            'success' => $this->success
        ];
    }

    /**
     * @param array $data
     * @return array
     */
    protected function _prepareData($data)
    {
        $keys = array_shift($data);

        foreach ($data as &$rewrite) {
            $rewrite = array_combine($keys, $rewrite);
        }

        return $data;
    }

    /**
     * @param array $rewrite
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function _validateRewrite(array $rewrite)
    {
        if (count($rewrite) != count(array_filter($rewrite, 'strlen'))) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Empty columns are not allowed.')
            );
        }
    }

    /**
     * @param array $rewrite
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function parse(array $rewrite)
    {
        array_walk($rewrite, 'trim');
        $parsedRewrite = [];

        if(!isset($rewrite['request_path'])) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Request path not set.')
            );
        }

        if(!isset($rewrite['target_path'])) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Target path not set.')
            );
        }

        if(!isset($rewrite['redirect'])) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Redirect type not set. Valid entries are 0, 301 and 302')
            );
        }

        if(!array_key_exists($rewrite['store_code'], $this->_publicStores)) {
            $validStores = null;
            foreach ($this->_publicStores as $storeName => $value) {
                $validStores .=  '"' . $storeName . '", ';
            }
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Store code "' . $rewrite['store_code'] . '" not found. Valid entries are: ' . substr($validStores, 0, -2))
            );
        }

        $parsedRewrite['request_path'] = $rewrite['request_path'];
        $parsedRewrite['target_path'] = $rewrite['target_path'];
        $parsedRewrite['redirect_type'] = $rewrite['redirect'];
        $parsedRewrite['entity_type'] = self::ENTITY_TYPE_CUSTOM;
        $parsedRewrite['store_id'] = $this->_publicStores[$rewrite['store_code']];

        return $parsedRewrite;
    }

    /**
     * Import single rate
     *
     * @param array $urlData
     * @return array regions cache populated with regions related to country of imported tax rate
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function _importUrl(array $urlData)
    {
        $this->_connection->insertOnDuplicate(
            $this->_entityTable,
            $urlData
        );
    }
}
