<?php

namespace Encore\Admin\Navigation;

use Encore\Admin\Facades\Admin;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Lang;

class AdminMenuPresenter
{
    /**
     * Build a permission-filtered menu tree and a flat searchable index.
     *
     * @param array         $menu
     * @param object|null   $user
     * @param callable|null $urlGenerator
     * @param callable|null $titleTranslator
     */
    public function present(
        array $menu,
        $user = null,
        callable $urlGenerator = null,
        callable $titleTranslator = null
    ): array {
        $user = $user ?: Admin::user();
        $urlGenerator = $urlGenerator ?: function (string $uri): string {
            return admin_url($uri);
        };
        $titleTranslator = $titleTranslator ?: function (string $title): string {
            $translation = 'admin.menu_titles.'.trim(str_replace(' ', '_', strtolower($title)));

            return Lang::has($translation) ? __($translation) : (string) admin_trans($title);
        };

        $tree = [];

        foreach ($menu as $item) {
            $node = $this->normalizeNode($item, $user, [], 1, $urlGenerator, $titleTranslator);

            if ($node !== null) {
                $tree[] = $node;
            }
        }

        $items = [];
        $this->flattenClickableItems($tree, $items);

        return [
            'tree' => $tree,
            'items' => $items,
            'userId' => (string) ($user->id ?? 'guest'),
        ];
    }

    private function normalizeNode(
        array $item,
        $user,
        array $parentPath,
        int $depth,
        callable $urlGenerator,
        callable $titleTranslator
    ): ?array {
        if (!$this->isVisible($item, $user)) {
            return null;
        }

        $title = $titleTranslator((string) Arr::get($item, 'title', ''));
        $path = array_merge($parentPath, [$title]);
        $uri = trim((string) Arr::get($item, 'uri', ''));
        $clickable = $uri !== '' && $uri !== '#';
        $external = $clickable && $this->isExternalUrl($uri);
        $children = [];

        foreach ((array) Arr::get($item, 'children', []) as $child) {
            $normalized = $this->normalizeNode(
                $child,
                $user,
                $path,
                $depth + 1,
                $urlGenerator,
                $titleTranslator
            );

            if ($normalized !== null) {
                $children[] = $normalized;
            }
        }

        if (!$clickable && empty($children)) {
            return null;
        }

        return [
            'id' => (string) Arr::get($item, 'id', ''),
            'parentId' => (string) Arr::get($item, 'parent_id', ''),
            'title' => $title,
            'icon' => (string) (Arr::get($item, 'icon') ?: 'fa-circle-o'),
            'uri' => $uri,
            'url' => $clickable ? ($external ? $uri : $urlGenerator(ltrim($uri, '/'))) : '',
            'external' => $external,
            'clickable' => $clickable,
            'depth' => $depth,
            'path' => $path,
            'children' => $children,
        ];
    }

    private function isVisible(array $item, $user): bool
    {
        return $user
            && $user->visible((array) Arr::get($item, 'roles', []))
            && $user->can(Arr::get($item, 'permission'));
    }

    private function isExternalUrl(string $uri): bool
    {
        return (bool) preg_match('#^(?:https?:)?//#i', $uri);
    }

    private function flattenClickableItems(array $nodes, array &$items): void
    {
        foreach ($nodes as $node) {
            if ($node['clickable']) {
                $item = $node;
                unset($item['children']);
                $items[] = $item;
            }

            if (!empty($node['children'])) {
                $this->flattenClickableItems($node['children'], $items);
            }
        }
    }
}
