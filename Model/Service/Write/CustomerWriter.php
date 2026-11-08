<?php

declare(strict_types=1);

namespace Byte8\Horizon\Model\Service\Write;

use Byte8\Horizon\Controller\Api\Admin\WriteException;
use Byte8\Horizon\Model\Config;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerMetadataInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Api\Data\RegionInterfaceFactory;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Directory\Api\CountryInformationAcquirerInterface;
use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\State\InputMismatchException;
use Magento\Newsletter\Model\SubscriberFactory;
use Magento\Newsletter\Model\SubscriptionManagerInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Customer writes for the `customers:write` scope: create an account (no
 * password — Magento emails a set-password link), update profile fields,
 * add / update addresses, and change the newsletter subscription when the
 * agent records where consent came from. Email changes and deletion are not
 * offered: both stay human, logged actions in admin. One customer per call.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class CustomerWriter
{
    private const NAME_FIELDS = ['firstname', 'lastname', 'middlename', 'prefix', 'suffix'];
    private const GENDERS = ['male' => 1, 'female' => 2, 'not_specified' => 3];
    private const ADDRESS_TEXT = ['firstname', 'lastname', 'company', 'city', 'postcode', 'telephone', 'vat_id'];

    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly CustomerInterfaceFactory $customerFactory,
        private readonly AccountManagementInterface $accountManagement,
        private readonly AddressRepositoryInterface $addressRepository,
        private readonly AddressInterfaceFactory $addressFactory,
        private readonly RegionInterfaceFactory $regionDataFactory,
        private readonly RegionFactory $regionFactory,
        private readonly DirectoryHelper $directoryHelper,
        private readonly CountryInformationAcquirerInterface $countryInformation,
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly CustomerMetadataInterface $customerMetadata,
        private readonly SubscriptionManagerInterface $subscriptionManager,
        private readonly SubscriberFactory $subscriberFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config
    ) {
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function create(array $body, bool $dryRun): array
    {
        $email = $this->email($body['email'] ?? null);
        $websiteId = $this->websiteId($body);
        $storeId = $this->storeId($body, $websiteId);
        try {
            $existing = $this->customerRepository->get($email, $websiteId);
            throw new WriteException(
                sprintf('A customer with this email already exists on website %d (id %d); use update_customer', $websiteId, (int) $existing->getId()),
                'CONFLICT',
                409
            );
        } catch (NoSuchEntityException) {
            // Good: the email is free on this website.
        }

        /** @var CustomerInterface $customer */
        $customer = $this->customerFactory->create();
        $customer->setEmail($email)->setWebsiteId($websiteId)->setStoreId($storeId);
        $changes = [
            ['field' => 'email', 'before' => null, 'after' => $email],
            ['field' => 'website_id', 'before' => null, 'after' => $websiteId],
        ];
        foreach (['firstname', 'lastname'] as $required) {
            if ($this->text($body, $required) === null) {
                throw new WriteException(sprintf('"%s" is required for a new customer', $required), 'VALIDATION', 400);
            }
        }
        $this->applyProfile($customer, $body, $changes, true);

        $addresses = [];
        foreach ((array) ($body['addresses'] ?? []) as $index => $input) {
            if (!is_array($input)) {
                throw new WriteException(sprintf('addresses[%d] must be an object', $index), 'VALIDATION', 400);
            }
            $address = $this->addressFactory->create();
            $addressChanges = [];
            $this->applyAddress($address, $input, $addressChanges, true, $customer);
            $addresses[] = $address;
            $changes[] = ['field' => sprintf('addresses[%d]', $index), 'before' => null, 'after' => $this->addressSummary($address)];
        }
        if ($addresses !== []) {
            $customer->setAddresses($addresses);
        }
        $welcome = !array_key_exists('send_welcome_email', $body) || $this->flag($body, 'send_welcome_email');

        if (!$dryRun) {
            try {
                $customer = $welcome
                    ? $this->accountManagement->createAccount($customer)
                    : $this->customerRepository->save($customer);
            } catch (InputMismatchException $e) {
                throw new WriteException($e->getMessage(), 'CONFLICT', 409);
            } catch (LocalizedException $e) {
                throw new WriteException('Magento refused the customer: ' . $e->getMessage(), 'VALIDATION', 422);
            }
        }
        $changes[] = ['field' => 'welcome_email', 'before' => null, 'after' => $welcome ? 'set-password email sent by Magento' : 'not sent'];

        return $this->result('create', $dryRun, !$dryRun, false, $customer, $changes, [
            'address_id' => null,
            'is_subscribed' => null,
            'welcome_email_sent' => !$dryRun && $welcome,
            'consent_source' => null,
        ]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function update(array $body, bool $dryRun): array
    {
        $customer = $this->load($body);
        if (array_key_exists('id', $body) && $body['id'] !== null && array_key_exists('email', $body) && $body['email'] !== null
            && mb_strtolower(trim((string) $body['email'])) !== mb_strtolower((string) $customer->getEmail())
        ) {
            throw new WriteException('Changing a customer\'s email is not available through Horizon; it stays a human action in admin', 'VALIDATION', 400);
        }
        $changes = [];
        $this->applyProfile($customer, $body, $changes, false);

        if (!$dryRun && $changes !== []) {
            try {
                $customer = $this->customerRepository->save($customer);
            } catch (LocalizedException $e) {
                throw new WriteException('Magento refused the change: ' . $e->getMessage(), 'VALIDATION', 422);
            }
        }
        return $this->result('update', $dryRun, !$dryRun && $changes !== [], $changes === [], $customer, $changes, [
            'address_id' => null,
            'is_subscribed' => null,
            'welcome_email_sent' => null,
            'consent_source' => null,
        ]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function saveAddress(array $body, bool $dryRun): array
    {
        $customer = $this->load($body, 'customer_id');
        $addressId = $body['address_id'] ?? null;
        $isNew = $addressId === null || $addressId === '';
        if ($isNew) {
            $address = $this->addressFactory->create();
            $address->setCustomerId((int) $customer->getId());
        } else {
            try {
                $address = $this->addressRepository->getById((int) $addressId);
            } catch (NoSuchEntityException) {
                throw new WriteException(sprintf('No address with id %d', (int) $addressId), 'NOT_FOUND', 404);
            }
            if ((int) $address->getCustomerId() !== (int) $customer->getId()) {
                throw new WriteException(sprintf('Address %d does not belong to customer %d', (int) $addressId, (int) $customer->getId()), 'VALIDATION', 400);
            }
        }
        $changes = [];
        $this->applyAddress($address, $body, $changes, $isNew, $customer);

        if (!$dryRun && $changes !== []) {
            try {
                $address = $this->addressRepository->save($address);
            } catch (LocalizedException $e) {
                throw new WriteException('Magento refused the address: ' . $e->getMessage(), 'VALIDATION', 422);
            }
            $customer = $this->customerRepository->getById((int) $customer->getId());
        }
        return $this->result($isNew ? 'address_create' : 'address_update', $dryRun, !$dryRun && $changes !== [], $changes === [], $customer, $changes, [
            'address_id' => $address->getId() !== null ? (int) $address->getId() : null,
            'is_subscribed' => null,
            'welcome_email_sent' => null,
            'consent_source' => null,
        ]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function newsletter(array $body, bool $dryRun): array
    {
        $customer = $this->load($body, 'customer_id');
        if (!array_key_exists('subscribed', $body) || $body['subscribed'] === null) {
            throw new WriteException('"subscribed" (true or false) is required', 'VALIDATION', 400);
        }
        $subscribed = $this->flag($body, 'subscribed');
        $consent = $this->text($body, 'consent_source');
        if ($consent === null || mb_strlen($consent) < 3) {
            throw new WriteException(
                'A newsletter change needs "consent_source": where and when the customer gave (or withdrew) consent, e.g. "phone call 2026-11-08, asked to be added" — it is kept in the audit trail',
                'CONSENT_REQUIRED',
                400
            );
        }
        $websiteId = (int) $customer->getWebsiteId();
        $storeId = (int) ($customer->getStoreId() ?: $this->storeManager->getWebsite($websiteId)->getDefaultStore()->getId());
        $before = $this->subscriberFactory->create()->loadByCustomer((int) $customer->getId(), $websiteId)->isSubscribed();
        $changes = [];
        if ($before !== $subscribed) {
            $changes[] = ['field' => 'is_subscribed', 'before' => $before, 'after' => $subscribed];
            $changes[] = ['field' => 'consent_source', 'before' => null, 'after' => $consent];
        }
        $after = $before;
        if (!$dryRun && $changes !== []) {
            try {
                $subscriber = $subscribed
                    ? $this->subscriptionManager->subscribeCustomer((int) $customer->getId(), $storeId)
                    : $this->subscriptionManager->unsubscribeCustomer((int) $customer->getId(), $storeId);
            } catch (LocalizedException $e) {
                throw new WriteException('Magento refused the subscription change: ' . $e->getMessage(), 'VALIDATION', 422);
            }
            $after = $subscriber->isSubscribed();
        } elseif ($dryRun) {
            $after = $subscribed;
        }
        return $this->result($subscribed ? 'subscribe' : 'unsubscribe', $dryRun, !$dryRun && $changes !== [], $changes === [], $customer, $changes, [
            'address_id' => null,
            'is_subscribed' => $after,
            'welcome_email_sent' => null,
            'consent_source' => $consent,
        ]);
    }

    // ------------------------------------------------------------- profile

    /**
     * @param array<string, mixed> $body
     * @param array<int, array<string, mixed>> $changes
     */
    private function applyProfile(CustomerInterface $customer, array $body, array &$changes, bool $isNew): void
    {
        foreach (self::NAME_FIELDS as $field) {
            $after = $this->text($body, $field);
            if ($after === null) {
                continue;
            }
            if (mb_strlen($after) > 255) {
                throw new WriteException(sprintf('"%s" is too long', $field), 'VALIDATION', 400);
            }
            $before = $isNew ? null : (string) $this->get($customer, $field);
            if ($isNew || $before !== $after) {
                $changes[] = ['field' => $field, 'before' => $before, 'after' => $after];
                $this->set($customer, $field, $after);
            }
        }
        if (array_key_exists('group', $body) || array_key_exists('group_id', $body)) {
            $groupId = $this->groupId($body);
            if ($groupId !== null) {
                $before = $isNew ? null : (int) $customer->getGroupId();
                if ($isNew || $before !== $groupId) {
                    $changes[] = ['field' => 'group', 'before' => $before === null ? null : $this->groupName($before), 'after' => $this->groupName($groupId)];
                    $customer->setGroupId($groupId);
                }
            }
        }
        $dob = $this->text($body, 'dob');
        if ($dob !== null) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob) !== 1 || !checkdate((int) substr($dob, 5, 2), (int) substr($dob, 8, 2), (int) substr($dob, 0, 4))) {
                throw new WriteException('"dob" must be a valid date YYYY-MM-DD', 'VALIDATION', 400);
            }
            $before = $isNew ? null : ($customer->getDob() !== null ? substr((string) $customer->getDob(), 0, 10) : null);
            if ($isNew || $before !== $dob) {
                $changes[] = ['field' => 'dob', 'before' => $before, 'after' => $dob];
                $customer->setDob($dob);
            }
        }
        $gender = $this->text($body, 'gender');
        if ($gender !== null) {
            $key = strtolower($gender);
            if (!isset(self::GENDERS[$key])) {
                throw new WriteException('"gender" must be male, female or not_specified', 'VALIDATION', 400);
            }
            $beforeId = $isNew ? null : (int) $customer->getGender();
            if ($isNew || $beforeId !== self::GENDERS[$key]) {
                $changes[] = ['field' => 'gender', 'before' => $beforeId === null ? null : (array_search($beforeId, self::GENDERS, true) ?: null), 'after' => $key];
                $customer->setGender(self::GENDERS[$key]);
            }
        }
        $taxvat = $this->text($body, 'taxvat');
        if ($taxvat !== null) {
            $before = $isNew ? null : (string) $customer->getTaxvat();
            if ($isNew || $before !== $taxvat) {
                $changes[] = ['field' => 'taxvat', 'before' => $before, 'after' => $taxvat];
                $customer->setTaxvat($taxvat);
            }
        }
        if (array_key_exists('attributes', $body) && $body['attributes'] !== null) {
            if (!is_array($body['attributes'])) {
                throw new WriteException('"attributes" must be an object of {code: value}', 'VALIDATION', 400);
            }
            $allowed = $this->config->getWritableCustomerAttributes();
            foreach ($body['attributes'] as $code => $value) {
                $code = (string) $code;
                if (!in_array($code, $allowed, true)) {
                    throw new WriteException(
                        sprintf('Attribute "%s" is not on this store\'s writable customer attributes list (Agent Writes → Writable Customer Attributes)', $code),
                        'ATTRIBUTE_NOT_ALLOWED',
                        403
                    );
                }
                $after = $this->customAttributeValue($code, $value);
                $before = $isNew ? null : $customer->getCustomAttribute($code)?->getValue();
                if ($isNew || (string) $before !== (string) $after) {
                    $changes[] = ['field' => $code, 'before' => $before, 'after' => $after];
                    $customer->setCustomAttribute($code, $after);
                }
            }
        }
    }

    private function customAttributeValue(string $code, mixed $value): string
    {
        try {
            $meta = $this->customerMetadata->getAttributeMetadata($code);
        } catch (NoSuchEntityException) {
            throw new WriteException(sprintf('No customer attribute "%s"', $code), 'NOT_FOUND', 404);
        }
        if (!$meta->isUserDefined()) {
            throw new WriteException(sprintf('"%s" is a system attribute; only custom customer attributes may be set here', $code), 'VALIDATION', 400);
        }
        if (is_array($value)) {
            throw new WriteException(sprintf('Attribute "%s" must be a scalar value', $code), 'VALIDATION', 400);
        }
        $value = is_bool($value) ? ($value ? '1' : '0') : trim((string) $value);
        if (in_array($meta->getFrontendInput(), ['select', 'multiselect'], true)) {
            foreach ($meta->getOptions() ?? [] as $option) {
                if ((string) $option->getValue() === $value || mb_strtolower((string) $option->getLabel()) === mb_strtolower($value)) {
                    return (string) $option->getValue();
                }
            }
            throw new WriteException(sprintf('Attribute "%s" has no option "%s"', $code, $value), 'OPTION_NOT_FOUND', 400);
        }
        if ($meta->getFrontendInput() === 'boolean') {
            $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($bool === null) {
                throw new WriteException(sprintf('Attribute "%s" must be true or false', $code), 'VALIDATION', 400);
            }
            return $bool ? '1' : '0';
        }
        return $value;
    }

    // ------------------------------------------------------------- address

    /**
     * @param array<string, mixed> $input
     * @param array<int, array<string, mixed>> $changes
     */
    private function applyAddress(AddressInterface $address, array $input, array &$changes, bool $isNew, CustomerInterface $customer): void
    {
        if ($isNew) {
            // Names default to the customer's own.
            if ($this->text($input, 'firstname') === null && $customer->getFirstname()) {
                $input['firstname'] = $customer->getFirstname();
            }
            if ($this->text($input, 'lastname') === null && $customer->getLastname()) {
                $input['lastname'] = $customer->getLastname();
            }
        }
        $countryId = $this->text($input, 'country_id');
        if ($countryId !== null) {
            $countryId = strtoupper($countryId);
            try {
                $this->countryInformation->getCountryInfo($countryId);
            } catch (NoSuchEntityException) {
                throw new WriteException(sprintf('"%s" is not a country this store ships to (two-letter ISO code expected)', $countryId), 'VALIDATION', 400);
            }
            $before = $isNew ? null : (string) $address->getCountryId();
            if ($isNew || $before !== $countryId) {
                $changes[] = ['field' => 'country_id', 'before' => $before, 'after' => $countryId];
                $address->setCountryId($countryId);
            }
        } elseif ($isNew) {
            throw new WriteException('A new address needs "country_id"', 'VALIDATION', 400);
        }
        $country = (string) $address->getCountryId();

        foreach (self::ADDRESS_TEXT as $field) {
            $after = $this->text($input, $field);
            if ($after === null) {
                continue;
            }
            $before = $isNew ? null : (string) $this->get($address, $field);
            if ($isNew || $before !== $after) {
                $changes[] = ['field' => $field, 'before' => $before, 'after' => $after];
                $this->set($address, $field, $after);
            }
        }
        if (array_key_exists('street', $input) && $input['street'] !== null) {
            $street = is_array($input['street']) ? array_values(array_filter(array_map('trim', array_map('strval', $input['street'])), static fn ($l) => $l !== '')) : [trim((string) $input['street'])];
            if ($street === [] || $street[0] === '') {
                throw new WriteException('"street" must be a non-empty string or array of lines', 'VALIDATION', 400);
            }
            $before = $isNew ? null : ($address->getStreet() ?? []);
            if ($isNew || $before !== $street) {
                $changes[] = ['field' => 'street', 'before' => $before, 'after' => $street];
                $address->setStreet($street);
            }
        }
        $regionInput = $this->text($input, 'region');
        $regionId = isset($input['region_id']) && is_numeric($input['region_id']) ? (int) $input['region_id'] : null;
        if ($regionInput !== null || $regionId !== null) {
            $region = $this->region($country, $regionInput, $regionId);
            $beforeRegion = $isNew ? null : (string) ($address->getRegion()?->getRegion() ?? '');
            if ($isNew || $beforeRegion !== (string) $region->getRegion()) {
                $changes[] = ['field' => 'region', 'before' => $beforeRegion, 'after' => (string) $region->getRegion()];
            }
            $address->setRegion($region);
            $address->setRegionId($region->getRegionId());
        } elseif ($this->directoryHelper->isRegionRequired($country) && ($isNew || !$address->getRegionId())) {
            throw new WriteException(sprintf('Addresses in %s need a "region" (name or code)', $country), 'VALIDATION', 400);
        }
        foreach (['firstname', 'lastname', 'city', 'telephone'] as $required) {
            if ($isNew && !$this->get($address, $required)) {
                throw new WriteException(sprintf('A new address needs "%s"', $required), 'VALIDATION', 400);
            }
        }
        if ($isNew && !$address->getStreet()) {
            throw new WriteException('A new address needs "street"', 'VALIDATION', 400);
        }
        if ($isNew && !$address->getPostcode() && !$this->directoryHelper->isZipCodeOptional($country)) {
            throw new WriteException(sprintf('Addresses in %s need a "postcode"', $country), 'VALIDATION', 400);
        }
        foreach (['default_billing' => 'setIsDefaultBilling', 'default_shipping' => 'setIsDefaultShipping'] as $flag => $setter) {
            if (!array_key_exists($flag, $input) || $input[$flag] === null) {
                continue;
            }
            $after = $this->flag($input, $flag);
            $before = $isNew ? null : (bool) ($flag === 'default_billing' ? $address->isDefaultBilling() : $address->isDefaultShipping());
            if ($isNew ? $after : $before !== $after) {
                $changes[] = ['field' => $flag, 'before' => $before, 'after' => $after];
                $address->$setter($after);
            }
        }
    }

    private function region(string $countryId, ?string $input, ?int $regionId): \Magento\Customer\Api\Data\RegionInterface
    {
        $region = $this->regionDataFactory->create();
        $model = $this->regionFactory->create();
        if ($regionId !== null) {
            $model->load($regionId);
        }
        if ((!$model->getId() || (string) $model->getCountryId() !== $countryId) && $input !== null) {
            $model = $this->regionFactory->create()->loadByCode($input, $countryId);
            if (!$model->getId()) {
                $model = $this->regionFactory->create()->loadByName($input, $countryId);
            }
        }
        if ($model->getId() && (string) $model->getCountryId() === $countryId) {
            return $region->setRegionId((int) $model->getId())->setRegionCode((string) $model->getCode())->setRegion((string) $model->getDefaultName());
        }
        if ($this->directoryHelper->isRegionRequired($countryId)) {
            throw new WriteException(sprintf('"%s" is not a region of %s; use the region name or code', $input ?? (string) $regionId, $countryId), 'VALIDATION', 400);
        }
        return $region->setRegion($input ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    private function addressSummary(AddressInterface $address): array
    {
        return [
            'firstname' => $address->getFirstname(),
            'lastname' => $address->getLastname(),
            'company' => $address->getCompany(),
            'street' => $address->getStreet(),
            'city' => $address->getCity(),
            'region' => $address->getRegion()?->getRegion(),
            'postcode' => $address->getPostcode(),
            'country_id' => $address->getCountryId(),
            'telephone' => $address->getTelephone(),
            'default_billing' => (bool) $address->isDefaultBilling(),
            'default_shipping' => (bool) $address->isDefaultShipping(),
        ];
    }

    // ------------------------------------------------------------- lookups

    /**
     * @param array<string, mixed> $body
     */
    private function load(array $body, string $idKey = 'id'): CustomerInterface
    {
        $id = $body[$idKey] ?? null;
        if ($id !== null && $id !== '') {
            if (!is_numeric($id)) {
                throw new WriteException(sprintf('"%s" must be numeric', $idKey), 'VALIDATION', 400);
            }
            try {
                return $this->customerRepository->getById((int) $id);
            } catch (NoSuchEntityException) {
                throw new WriteException(sprintf('No customer with id %d', (int) $id), 'NOT_FOUND', 404);
            }
        }
        $email = $this->text($body, 'email');
        if ($email === null) {
            throw new WriteException(sprintf('Identify the customer by "%s" or "email"', $idKey), 'VALIDATION', 400);
        }
        $websiteId = $this->websiteId($body);
        try {
            return $this->customerRepository->get($this->email($email), $websiteId);
        } catch (NoSuchEntityException) {
            throw new WriteException(sprintf('No customer with email "%s" on website %d', $email, $websiteId), 'NOT_FOUND', 404);
        }
    }

    private function email(mixed $value): string
    {
        $email = mb_strtolower(trim((string) ($value ?? '')));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new WriteException('A valid "email" is required', 'VALIDATION', 400);
        }
        return $email;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function websiteId(array $body): int
    {
        if (!isset($body['website_id']) || $body['website_id'] === '') {
            return (int) $this->storeManager->getStore()->getWebsiteId();
        }
        if (!is_numeric($body['website_id'])) {
            throw new WriteException('"website_id" must be numeric', 'VALIDATION', 400);
        }
        try {
            return (int) $this->storeManager->getWebsite((int) $body['website_id'])->getId();
        } catch (LocalizedException) {
            throw new WriteException(sprintf('No website with id %d', (int) $body['website_id']), 'NOT_FOUND', 404);
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function storeId(array $body, int $websiteId): int
    {
        if (!isset($body['store_id']) || $body['store_id'] === '') {
            $current = $this->storeManager->getStore();
            return (int) $current->getWebsiteId() === $websiteId
                ? (int) $current->getId()
                : (int) $this->storeManager->getWebsite($websiteId)->getDefaultStore()->getId();
        }
        if (!is_numeric($body['store_id'])) {
            throw new WriteException('"store_id" must be numeric', 'VALIDATION', 400);
        }
        try {
            $store = $this->storeManager->getStore((int) $body['store_id']);
        } catch (NoSuchEntityException) {
            throw new WriteException(sprintf('No store view with id %d', (int) $body['store_id']), 'NOT_FOUND', 404);
        }
        if ((int) $store->getWebsiteId() !== $websiteId) {
            throw new WriteException(sprintf('Store view %d does not belong to website %d', (int) $store->getId(), $websiteId), 'VALIDATION', 400);
        }
        return (int) $store->getId();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function groupId(array $body): ?int
    {
        if (isset($body['group_id']) && $body['group_id'] !== '') {
            if (!is_numeric($body['group_id'])) {
                throw new WriteException('"group_id" must be numeric', 'VALIDATION', 400);
            }
            $id = (int) $body['group_id'];
            if ($id === 0 || !isset($this->groups()[$id])) {
                throw new WriteException(sprintf('No customer group with id %d', $id), 'NOT_FOUND', 404);
            }
            return $id;
        }
        $name = $this->text($body, 'group');
        if ($name === null) {
            return null;
        }
        foreach ($this->groups() as $id => $code) {
            if ($id !== 0 && mb_strtolower($code) === mb_strtolower($name)) {
                return $id;
            }
        }
        throw new WriteException(sprintf('No customer group named "%s"; groups: %s', $name, implode(', ', array_filter($this->groups(), static fn ($id) => $id !== 0, ARRAY_FILTER_USE_KEY))), 'NOT_FOUND', 404);
    }

    /** @var array<int, string>|null */
    private ?array $groupCache = null;

    /**
     * @return array<int, string>
     */
    private function groups(): array
    {
        if ($this->groupCache === null) {
            $this->groupCache = [];
            foreach ($this->groupRepository->getList($this->searchCriteriaBuilder->create())->getItems() as $group) {
                $this->groupCache[(int) $group->getId()] = (string) $group->getCode();
            }
        }
        return $this->groupCache;
    }

    private function groupName(int $id): string
    {
        return $this->groups()[$id] ?? (string) $id;
    }

    /** Data objects have no getData(): call the typed getter by field name. */
    private function get(object $object, string $field): mixed
    {
        return $object->{'get' . str_replace('_', '', ucwords($field, '_'))}();
    }

    private function set(object $object, string $field, mixed $value): void
    {
        $object->{'set' . str_replace('_', '', ucwords($field, '_'))}($value);
    }

    /**
     * @param array<int, array<string, mixed>> $changes
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function result(string $action, bool $dryRun, bool $saved, bool $noop, CustomerInterface $customer, array $changes, array $extra): array
    {
        return [
            'action' => $action,
            'dry_run' => $dryRun,
            'saved' => $saved,
            'noop' => $noop,
            'entity_type' => 'customer',
            'entity_id' => $customer->getId() !== null ? (string) $customer->getId() : null,
            'customer_id' => $customer->getId() !== null ? (int) $customer->getId() : null,
            'email' => (string) $customer->getEmail(),
            'firstname' => (string) $customer->getFirstname(),
            'lastname' => (string) $customer->getLastname(),
            'group' => $customer->getGroupId() !== null ? $this->groupName((int) $customer->getGroupId()) : null,
            'website_id' => (int) $customer->getWebsiteId(),
        ] + $extra + ['changes' => $changes];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function text(array $body, string $key): ?string
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            return null;
        }
        if (!is_scalar($body[$key])) {
            throw new WriteException(sprintf('"%s" must be a string', $key), 'VALIDATION', 400);
        }
        $value = trim((string) $body[$key]);
        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function flag(array $body, string $key): bool
    {
        return filter_var($body[$key] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            ?? throw new WriteException(sprintf('"%s" must be a boolean', $key), 'VALIDATION', 400);
    }
}
