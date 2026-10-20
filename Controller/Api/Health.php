<?php

declare(strict_types=1);

namespace Byte8\Horizon\Controller\Api;

use Byte8\Horizon\Model\Config;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;

class Health extends AbstractAction
{
    private const MODULE_NAME = 'Byte8_Horizon';
    private const VERSION_FALLBACK = 'unknown';

    private ?string $resolvedVersion = null;

    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        Config $config,
        private readonly ProductMetadataInterface $productMetadata,
        private readonly ComponentRegistrar $componentRegistrar,
        private readonly File $fileDriver,
        private readonly JsonSerializer $jsonSerializer
    ) {
        parent::__construct($request, $jsonFactory, $config);
    }

    protected function handleRequest(Json $result): Json
    {
        return $result->setData([
            'version' => $this->getModuleVersion(),
            'magento_version' => $this->productMetadata->getVersion(),
        ]);
    }

    /**
     * Read the installed module version from composer.json so the gateway and
     * the Horizon dashboard report the real package version, not a constant
     * that has to be bumped by hand on every release.
     */
    private function getModuleVersion(): string
    {
        if ($this->resolvedVersion !== null) {
            return $this->resolvedVersion;
        }

        $this->resolvedVersion = self::VERSION_FALLBACK;

        $modulePath = $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, self::MODULE_NAME);
        if ($modulePath === null) {
            return $this->resolvedVersion;
        }

        $composerFile = $modulePath . '/composer.json';
        try {
            if (!$this->fileDriver->isExists($composerFile)) {
                return $this->resolvedVersion;
            }
            $data = $this->jsonSerializer->unserialize($this->fileDriver->fileGetContents($composerFile));
        } catch (\Throwable) {
            return $this->resolvedVersion;
        }

        if (is_array($data) && isset($data['version']) && is_string($data['version'])) {
            $this->resolvedVersion = $data['version'];
        }

        return $this->resolvedVersion;
    }
}
