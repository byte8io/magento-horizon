<?php

declare(strict_types=1);

namespace Byte8\Horizon\Controller\Api;

use Byte8\Horizon\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Module\PackageInfo;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Store\Model\ScopeInterface;

class Health extends AbstractAction
{
    private const MODULE_NAME = 'Byte8_Horizon';
    private const VERSION_FALLBACK = 'unknown';

    private const XML_PATH_CORE_ROBOTS = 'design/search_engine_robots/custom_instructions';
    private const XML_PATH_SEOSUITE_ROBOTS_ACTIVE = 'byte8_seosuite/robots/is_active';
    private const XML_PATH_SEOSUITE_ROBOTS_RULES = 'byte8_seosuite/robots/custom_rules';

    private ?string $resolvedVersion = null;

    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        Config $config,
        private readonly ProductMetadataInterface $productMetadata,
        private readonly ComponentRegistrar $componentRegistrar,
        private readonly File $fileDriver,
        private readonly JsonSerializer $jsonSerializer,
        private readonly ModuleListInterface $moduleList,
        private readonly PackageInfo $packageInfo,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Filesystem $filesystem
    ) {
        parent::__construct($request, $jsonFactory, $config);
    }

    protected function handleRequest(Json $result): Json
    {
        return $result->setData([
            'version' => $this->getModuleVersion(),
            'magento_version' => $this->productMetadata->getVersion(),
            'robots' => $this->getRobotsInfo(),
            'modules' => $this->getModulesInfo(),
            // Phase 8.1: lets the dashboard show whether the write key is in
            // place and which scopes the merchant granted in Magento.
            'write' => [
                'configured' => $this->config->getWriteApiKey() !== null,
                'scopes' => $this->config->getWriteScopes(),
            ],
        ]);
    }

    /**
     * Where this store's robots.txt comes from, so the gateway's AI readiness
     * report can point the merchant at the right place to edit it. A static
     * pub/robots.txt always wins over anything Magento renders.
     *
     * @return array<string, mixed>
     */
    private function getRobotsInfo(): array
    {
        $staticFile = false;
        try {
            $staticFile = $this->filesystem->getDirectoryRead(DirectoryList::PUB)->isExist('robots.txt');
        } catch (\Throwable) {
            // Unreadable pub dir: report as not static.
        }

        $coreInstructions = trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_CORE_ROBOTS,
            ScopeInterface::SCOPE_STORE
        ));

        $seoSuiteRules = false;
        if ($this->moduleList->has('Byte8_SeoSuite')
            && $this->scopeConfig->isSetFlag(self::XML_PATH_SEOSUITE_ROBOTS_ACTIVE, ScopeInterface::SCOPE_STORE)
        ) {
            $seoSuiteRules = trim((string) $this->scopeConfig->getValue(
                self::XML_PATH_SEOSUITE_ROBOTS_RULES,
                ScopeInterface::SCOPE_STORE
            )) !== '';
        }

        if ($staticFile) {
            $source = 'static';
        } elseif ($seoSuiteRules) {
            $source = 'seosuite';
        } else {
            $source = 'core';
        }

        return [
            'source' => $source,
            'static_file' => $staticFile,
            'core_custom_instructions' => $coreInstructions !== '',
            'seosuite_rules' => $seoSuiteRules,
        ];
    }

    /**
     * Modules the readiness report reasons about: SEO Suite (structured data,
     * robots, llms.txt generator) and Hyvä (ships its own product JSON-LD).
     *
     * @return array<string, mixed>
     */
    private function getModulesInfo(): array
    {
        $seoSuite = null;
        if ($this->moduleList->has('Byte8_SeoSuite')) {
            try {
                $seoSuite = $this->packageInfo->getVersion('Byte8_SeoSuite') ?: 'unknown';
            } catch (\Throwable) {
                $seoSuite = 'unknown';
            }
        }

        return [
            'seosuite' => $seoSuite,
            'hyva' => $this->moduleList->has('Hyva_Theme'),
        ];
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
