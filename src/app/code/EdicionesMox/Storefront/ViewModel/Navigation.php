<?php
declare(strict_types=1);

namespace EdicionesMox\Storefront\ViewModel;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class Navigation implements ArgumentInterface
{
    public function __construct(
        private readonly CustomerSession $customerSession
    ) {
    }

    public function getAriaLabelToggleNav(): string
    {
        return (string) __('Toggle main navigation');
    }

    public function isCustomerLoggedIn(): bool
    {
        return $this->customerSession->isLoggedIn();
    }
}
