<?php
/**
 * Url Rewrite Import admin controller
 *
 * @category    Jworks
 * @package     Jworks_UrlRewriteImport
 * @author Jitheesh V O <jitheeshvo@gmail.com>
 * @copyright Copyright (c) 2017 Jworks Digital ()
 */
namespace Jworks\UrlRewriteImport\Controller\Adminhtml\Import;

use Magento\Framework\Controller\ResultFactory;

/**
 * Class ImportPost
 * @package Jworks\UrlRewriteImport\Controller\Adminhtml\Import
 */
class ImportPost extends \Jworks\UrlRewriteImport\Controller\Adminhtml\Import
{
    /**
     * import action from import/export tax
     *
     * @return \Magento\Backend\Model\View\Result\Redirect
     */
    public function execute()
    {
        if ($this->getRequest()->isPost() && !empty($_FILES['import_rewrites_file']['tmp_name'])) {
            try {
                /** @var $importHandler \Jworks\UrlRewriteImport\Model\UrlRewrite\CsvImportHandler */
                $importHandler = $this->_objectManager->create('Jworks\UrlRewriteImport\Model\UrlRewrite\CsvImportHandler');
                $result = $importHandler->importFromCsvFile($this->getRequest()->getFiles('import_rewrites_file'));

                $resultLog = substr(print_r($result, true), 7);
                $resultLog = substr($resultLog, 0, -2);

                $this->messageManager->addSuccess(__('The url rewrites import has been run. Results are below'));
                $this->messageManager->addSuccess('<pre>' . $resultLog . '</pre>');
            } catch (\Magento\Framework\Exception\LocalizedException $e) {
                $this->messageManager->addError($e->getMessage());
            } catch (\Exception $e) {
                $this->messageManager->addError(__($e->getMessage()));
            }
        } else {
            $this->messageManager->addError(__('Invalid file upload attempt'));
        }
        /** @var \Magento\Backend\Model\View\Result\Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $resultRedirect->setUrl($this->_redirect->getRedirectUrl());
        return $resultRedirect;
    }
}
