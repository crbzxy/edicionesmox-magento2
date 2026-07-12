<?php
declare(strict_types=1);

namespace EdicionesMox\LandingPage\Controller\Page;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

class View implements HttpGetActionInterface
{
    public function __construct(
        private readonly PageFactory $pageFactory
    ) {
    }

    public function execute(): Page
    {
        return $this->pageFactory->create();
    }
}
