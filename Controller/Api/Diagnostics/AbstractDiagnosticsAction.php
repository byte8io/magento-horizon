<?php

declare(strict_types=1);

namespace Byte8\Horizon\Controller\Api\Diagnostics;

use Byte8\Horizon\Api\Diagnostics\SourceInterface;
use Byte8\Horizon\Controller\Api\AbstractAction;
use Byte8\Horizon\Model\Config;
use Byte8\Horizon\Model\Diagnostics\SourcePool;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;

/**
 * Base for `/horizon/api/v1/diagnostics/*`. One gate, on the module side so a
 * bypassed gateway gets nothing: the request must carry the store's
 * **diagnostics** key. The read key and the write key are refused here, and
 * the diagnostics key is refused on every other route, so a support agent's
 * session can read integration diagnostics and nothing else.
 */
abstract class AbstractDiagnosticsAction extends AbstractAction
{
    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        Config $config,
        protected readonly SourcePool $sources,
        protected readonly LoggerInterface $logger
    ) {
        parent::__construct($request, $jsonFactory, $config);
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();

        if (!$this->config->isEnabled()) {
            return $result->setHttpResponseCode(503)->setData(['error' => 'Horizon API is disabled', 'code' => 'DISABLED']);
        }
        $diagnosticsKey = $this->config->getDiagnosticsApiKey();
        if ($diagnosticsKey === null) {
            return $result->setHttpResponseCode(503)->setData([
                'error' => 'No diagnostics key configured for this store',
                'code' => 'DIAGNOSTICS_NOT_CONFIGURED',
            ]);
        }
        $requestKey = $this->getRequestApiKey();
        if ($requestKey === null || !hash_equals($diagnosticsKey, $requestKey)) {
            return $result->setHttpResponseCode(401)->setData([
                'error' => 'This endpoint requires the store diagnostics key',
                'code' => 'UNAUTHORIZED',
            ]);
        }

        try {
            return $this->handleRequest($result);
        } catch (DiagnosticsException $e) {
            return $result->setHttpResponseCode($e->getStatus())->setData([
                'error' => $e->getMessage(),
                'code' => $e->getErrorCode(),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $result->setHttpResponseCode(400)->setData(['error' => $e->getMessage(), 'code' => 'BAD_REQUEST']);
        } catch (\Throwable $e) {
            $this->logger->error('Horizon diagnostics request failed: ' . $e->getMessage(), ['exception' => $e]);
            return $result->setHttpResponseCode(500)->setData(['error' => 'Internal server error', 'code' => 'INTERNAL_ERROR']);
        }
    }

    /**
     * The source named by the `source` parameter, or the only available one.
     *
     * @throws DiagnosticsException
     */
    protected function resolveSource(): SourceInterface
    {
        $type = $this->getStringParam('source');
        $source = $this->sources->get($type);
        if ($source !== null) {
            return $source;
        }

        $available = array_keys($this->sources->getAvailable());
        if (!$available) {
            throw new DiagnosticsException(
                'No integration on this store reports diagnostics',
                'SOURCE_NOT_FOUND',
                404
            );
        }
        if ($type === null) {
            throw new DiagnosticsException(
                sprintf('Several integrations report diagnostics; pass source=%s', implode('|', $available)),
                'SOURCE_REQUIRED',
                400
            );
        }

        throw new DiagnosticsException(
            sprintf('Unknown diagnostics source "%s"; available: %s', $type, implode(', ', $available)),
            'SOURCE_NOT_FOUND',
            404
        );
    }

    /**
     * @return string[]
     */
    protected function getListParam(string $name): array
    {
        $value = $this->request->getParam($name);
        if ($value === null || $value === '') {
            return [];
        }
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        return array_values(array_filter(array_map(static fn ($v) => trim((string) $v), (array) $value)));
    }
}
