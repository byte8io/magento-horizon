<?php

declare(strict_types=1);

namespace Byte8\Horizon\Model\Content;

use Byte8\Horizon\Api\Content\ProviderInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Magefan Blog posts, read straight from its tables so this module carries no
 * dependency on the blog extension: when Magefan_Blog is absent or disabled
 * the provider reports itself unavailable and nothing else changes.
 *
 * URL shape follows Magefan's permalink settings (`mfblog/permalink/*`):
 * "default" = /{route}/{post_route}/{identifier}, "short" = /{route}/{identifier},
 * plus the optional suffix. Category-in-path permalinks fall back to the
 * plain form, which Magefan still resolves.
 */
class MagefanBlogProvider implements ProviderInterface
{
    private const SCAN_LIMIT = 200;
    private const TABLE_POST = 'magefan_blog_post';
    private const TABLE_POST_STORE = 'magefan_blog_post_store';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly ModuleManager $moduleManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly HtmlText $text
    ) {
    }

    public function getType(): string
    {
        return 'post';
    }

    public function getLabel(): string
    {
        return 'Blog post';
    }

    public function isAvailable(): bool
    {
        if (!$this->moduleManager->isEnabled('Magefan_Blog')) {
            return false;
        }
        if (!$this->scopeConfig->isSetFlag('mfblog/general/enabled', ScopeInterface::SCOPE_STORE)) {
            return false;
        }
        $connection = $this->resourceConnection->getConnection();
        return $connection->isTableExists($this->resourceConnection->getTableName(self::TABLE_POST))
            && $connection->isTableExists($this->resourceConnection->getTableName(self::TABLE_POST_STORE));
    }

    public function search(array $terms, int $limit, int $storeId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $this->baseSelect($storeId)
            ->columns(['post_id', 'identifier', 'title', 'short_content', 'content', 'publish_time'])
            ->order('p.publish_time DESC')
            ->limit(self::SCAN_LIMIT);
        if ($terms !== []) {
            $clauses = [];
            foreach ($terms as $term) {
                $like = '%' . $this->escapeLike($term) . '%';
                $clauses[] = $connection->quoteInto('p.title LIKE ?', $like)
                    . ' OR ' . $connection->quoteInto('p.content LIKE ?', $like)
                    . ' OR ' . $connection->quoteInto('p.short_content LIKE ?', $like);
            }
            $select->where('(' . implode(') OR (', $clauses) . ')');
        }

        $items = [];
        foreach ($connection->fetchAll($select) as $row) {
            $body = $this->text->toPlainText((string) ($row['short_content'] ?: '') . "\n" . (string) $row['content']);
            $items[] = [
                'type' => $this->getType(),
                'id' => (string) $row['identifier'],
                'title' => (string) $row['title'],
                'url' => $this->postUrl((string) $row['identifier'], $storeId),
                'excerpt' => $this->text->excerpt($body, $terms),
                'published_at' => $row['publish_time'] ? (string) $row['publish_time'] : null,
                'score' => $terms === [] ? 0 : $this->text->score($terms, (string) $row['title'], $body),
            ];
        }
        return $items;
    }

    public function get(string $identifier, int $storeId): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $this->baseSelect($storeId)
            ->columns(['post_id', 'identifier', 'title', 'content', 'publish_time'])
            ->limit(1);
        if (ctype_digit($identifier)) {
            $select->where('p.post_id = ?', (int) $identifier);
        } else {
            $select->where('p.identifier = ?', $identifier);
        }
        $row = $connection->fetchRow($select);
        if (!$row) {
            return null;
        }
        return [
            'type' => $this->getType(),
            'id' => (string) $row['identifier'],
            'title' => (string) $row['title'],
            'url' => $this->postUrl((string) $row['identifier'], $storeId),
            'content' => $this->text->toPlainText((string) $row['content']),
            'published_at' => $row['publish_time'] ? (string) $row['publish_time'] : null,
        ];
    }

    /**
     * Active, published posts visible on this store (store 0 = all stores).
     */
    private function baseSelect(int $storeId): \Magento\Framework\DB\Select
    {
        $connection = $this->resourceConnection->getConnection();
        return $connection->select()
            ->from(['p' => $this->resourceConnection->getTableName(self::TABLE_POST)], [])
            ->join(
                ['ps' => $this->resourceConnection->getTableName(self::TABLE_POST_STORE)],
                'ps.post_id = p.post_id',
                []
            )
            ->where('p.is_active = 1')
            ->where('p.publish_time IS NULL OR p.publish_time <= NOW()')
            ->where('ps.store_id IN (?)', [0, $storeId])
            ->group('p.post_id');
    }

    private function postUrl(string $identifier, int $storeId): string
    {
        $cfg = fn (string $key, string $default): string => trim(
            (string) ($this->scopeConfig->getValue('mfblog/permalink/' . $key, ScopeInterface::SCOPE_STORE, $storeId) ?: $default),
            '/'
        );
        $route = $cfg('route', 'blog');
        $path = $cfg('type', 'default') === 'short'
            ? $route . '/' . $identifier
            : $route . '/' . $cfg('post_route', 'post') . '/' . $identifier;
        $suffix = (string) $this->scopeConfig->getValue('mfblog/permalink/post_sufix', ScopeInterface::SCOPE_STORE, $storeId);
        $base = rtrim((string) $this->storeManager->getStore($storeId)->getBaseUrl(), '/');
        return $base . '/' . $path . $suffix;
    }

    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
