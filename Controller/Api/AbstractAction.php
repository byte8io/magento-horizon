<?php

declare(strict_types=1);

namespace Byte8\Horizon\Controller\Api;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Byte8\Horizon\Model\Config;

abstract class AbstractAction implements HttpGetActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        protected readonly RequestInterface $request,
        protected readonly JsonFactory $jsonFactory,
        protected readonly Config $config
    ) {
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();

        if (!$this->config->isEnabled()) {
            return $result->setHttpResponseCode(503)->setData([
                'error' => 'Horizon API is disabled',
                'code' => 'DISABLED',
            ]);
        }

        $configApiKey = $this->config->getApiKey();
        if (empty($configApiKey)) {
            return $result->setHttpResponseCode(503)->setData([
                'error' => 'API key not configured',
                'code' => 'NOT_CONFIGURED',
            ]);
        }

        // Reads accept either key: an agent holding the write key must still
        // be able to look things up. Writes (Admin\AbstractWriteAction) accept
        // only the write key.
        $requestApiKey = $this->getRequestApiKey();
        $writeApiKey = $this->config->getWriteApiKey();
        $accepted = $requestApiKey !== null && (
            hash_equals($configApiKey, $requestApiKey)
            || ($writeApiKey !== null && hash_equals($writeApiKey, $requestApiKey))
        );
        if (!$accepted) {
            return $result->setHttpResponseCode(401)->setData([
                'error' => 'Invalid or missing API key',
                'code' => 'UNAUTHORIZED',
            ]);
        }

        try {
            return $this->handleRequest($result);
        } catch (\Exception $e) {
            return $result->setHttpResponseCode(500)->setData([
                'error' => 'Internal server error',
                'code' => 'INTERNAL_ERROR',
            ]);
        }
    }

    abstract protected function handleRequest(Json $result): Json;

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    protected function getRequestApiKey(): ?string
    {
        $horizonKey = $this->request->getHeader('X-Horizon-Key');
        if (!empty($horizonKey)) {
            return $horizonKey;
        }

        $authHeader = $this->request->getHeader('Authorization');
        if (!empty($authHeader) && str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }

        return null;
    }

    protected function getIntParam(string $name, int $default): int
    {
        $value = $this->request->getParam($name);
        return $value !== null ? (int) $value : $default;
    }

    protected function getBoolParam(string $name, bool $default): bool
    {
        $value = $this->request->getParam($name);
        if ($value === null) {
            return $default;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    protected function getStringParam(string $name): ?string
    {
        $value = $this->request->getParam($name);
        return $value !== null && $value !== '' ? (string) $value : null;
    }
}
