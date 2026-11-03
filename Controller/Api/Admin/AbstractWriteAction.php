<?php

declare(strict_types=1);

namespace Byte8\Horizon\Controller\Api\Admin;

use Byte8\Horizon\Controller\Api\AbstractAction;
use Byte8\Horizon\Model\Config;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Psr\Log\LoggerInterface;

/**
 * Base for every endpoint that changes the store.
 *
 * Three gates, all on the module side so a bypassed gateway changes nothing:
 * the request must carry the **write** key (the read key the gateway uses for
 * catalog calls never writes, even if leaked); the store must have granted the
 * endpoint's scope under Stores → Configuration → Byte8 → Horizon; and the
 * body is JSON with an optional `dry_run` flag — a dry run validates and
 * returns the change set without saving anything.
 */
abstract class AbstractWriteAction extends AbstractAction implements HttpPostActionInterface
{
    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        Config $config,
        private readonly JsonSerializer $serializer,
        protected readonly LoggerInterface $logger
    ) {
        parent::__construct($request, $jsonFactory, $config);
    }

    /** One of `Model\Config\Source\WriteScope::*`. */
    abstract protected function requiredScope(): string;

    /**
     * @param array<string, mixed> $body Decoded request body without `dry_run`.
     */
    abstract protected function handleWrite(array $body, bool $dryRun, Json $result): Json;

    /**
     * Satisfies the read base class; writes never reach it because
     * {@see execute()} is overridden with the write gates.
     */
    protected function handleRequest(Json $result): Json
    {
        return $result->setHttpResponseCode(405)->setData(['error' => 'Use POST', 'code' => 'METHOD_NOT_ALLOWED']);
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();

        if (!$this->config->isEnabled()) {
            return $result->setHttpResponseCode(503)->setData(['error' => 'Horizon API is disabled', 'code' => 'DISABLED']);
        }
        $writeKey = $this->config->getWriteApiKey();
        if ($writeKey === null || $writeKey === '') {
            return $result->setHttpResponseCode(503)->setData([
                'error' => 'No write key configured for this store',
                'code' => 'WRITE_NOT_CONFIGURED',
            ]);
        }
        $requestKey = $this->getRequestApiKey();
        if ($requestKey === null || !hash_equals($writeKey, $requestKey)) {
            return $result->setHttpResponseCode(401)->setData([
                'error' => 'This endpoint requires the store write key',
                'code' => 'UNAUTHORIZED',
            ]);
        }
        $scope = $this->requiredScope();
        if (!in_array($scope, $this->config->getWriteScopes(), true)) {
            return $result->setHttpResponseCode(403)->setData([
                'error' => sprintf('Scope "%s" is not granted to the write key of this store', $scope),
                'code' => 'SCOPE_NOT_GRANTED',
                'scope' => $scope,
            ]);
        }

        $raw = (string) $this->request->getContent();
        try {
            $body = $raw === '' ? [] : $this->serializer->unserialize($raw);
        } catch (\Throwable) {
            return $result->setHttpResponseCode(400)->setData(['error' => 'Body must be JSON', 'code' => 'BAD_REQUEST']);
        }
        if (!is_array($body)) {
            return $result->setHttpResponseCode(400)->setData(['error' => 'Body must be a JSON object', 'code' => 'BAD_REQUEST']);
        }
        $dryRun = filter_var($body['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        unset($body['dry_run']);

        try {
            return $this->handleWrite($body, $dryRun, $result);
        } catch (WriteException $e) {
            return $result->setHttpResponseCode($e->getStatus())->setData([
                'error' => $e->getMessage(),
                'code' => $e->getCode_(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Horizon write failed: ' . $e->getMessage(), ['exception' => $e]);
            return $result->setHttpResponseCode(500)->setData(['error' => 'Internal server error', 'code' => 'INTERNAL_ERROR']);
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function stringField(array $body, string $key): ?string
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            return null;
        }
        if (!is_scalar($body[$key])) {
            throw new WriteException(sprintf('Field "%s" must be a string', $key), 'VALIDATION', 400);
        }
        return (string) $body[$key];
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function boolField(array $body, string $key): ?bool
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            return null;
        }
        return filter_var($body[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            ?? throw new WriteException(sprintf('Field "%s" must be a boolean', $key), 'VALIDATION', 400);
    }
}
