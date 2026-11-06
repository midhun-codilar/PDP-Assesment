<?php
declare(strict_types=1);

namespace Codilar\Twoxl\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Everything under Stores > Configuration > Enquiries > Product Enquiries.
 *
 * Read at store scope so a storefront can turn the form off, or point the notification
 * somewhere else, without touching the other one. Defaults live in etc/config.xml.
 */
class EnquiryConfig
{
    private const PATH_ENABLED = 'twoxl_enquiry/general/enabled';
    private const PATH_HEADING = 'twoxl_enquiry/general/heading';
    private const PATH_INTRO = 'twoxl_enquiry/general/intro_text';
    private const PATH_SUCCESS = 'twoxl_enquiry/general/success_message';
    private const PATH_REQUIRE_PHONE = 'twoxl_enquiry/form/require_phone';
    private const PATH_MESSAGE_MAX = 'twoxl_enquiry/form/message_max_length';
    private const PATH_NOTIFY = 'twoxl_enquiry/notification/enabled';
    private const PATH_RECIPIENTS = 'twoxl_enquiry/notification/recipient_email';
    private const PATH_SENDER = 'twoxl_enquiry/notification/sender';
    private const PATH_TEMPLATE = 'twoxl_enquiry/notification/template';

    /** Used when the configured length is missing or nonsense; also the column's practical ceiling. */
    private const FALLBACK_MESSAGE_MAX = 2000;

    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::PATH_ENABLED, $storeId);
    }

    public function getHeading(?int $storeId = null): string
    {
        return $this->value(self::PATH_HEADING, $storeId);
    }

    public function getIntroText(?int $storeId = null): string
    {
        return $this->value(self::PATH_INTRO, $storeId);
    }

    public function getSuccessMessage(?int $storeId = null): string
    {
        return $this->value(self::PATH_SUCCESS, $storeId);
    }

    public function isPhoneRequired(?int $storeId = null): bool
    {
        return $this->flag(self::PATH_REQUIRE_PHONE, $storeId);
    }

    /**
     * Clamped to something the message column can actually hold.
     */
    public function getMessageMaxLength(?int $storeId = null): int
    {
        $max = (int)$this->value(self::PATH_MESSAGE_MAX, $storeId);

        return $max > 0 ? min($max, 5000) : self::FALLBACK_MESSAGE_MAX;
    }

    public function isNotificationEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::PATH_NOTIFY, $storeId);
    }

    /**
     * @return array<int, string> Recipients, already split and trimmed.
     */
    public function getRecipients(?int $storeId = null): array
    {
        $raw = $this->value(self::PATH_RECIPIENTS, $storeId);

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    public function getSenderIdentity(?int $storeId = null): string
    {
        return $this->value(self::PATH_SENDER, $storeId) ?: 'general';
    }

    /**
     * The fallback id matches PATH_TEMPLATE with slashes swapped for underscores, which is the
     * id Magento's email template picker derives from the config path for the "(Default)" entry.
     */
    public function getTemplateId(?int $storeId = null): string
    {
        return $this->value(self::PATH_TEMPLATE, $storeId) ?: 'twoxl_enquiry_notification_template';
    }

    private function value(string $path, ?int $storeId): string
    {
        return trim((string)$this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId));
    }

    private function flag(string $path, ?int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
