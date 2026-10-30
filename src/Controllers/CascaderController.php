<?php

namespace Encore\Admin\Controllers;

use Encore\Admin\Cascader\Source;
use Encore\Admin\Security\ComponentSubmission;
use Illuminate\Http\Request;
use InvalidArgumentException;

class CascaderController
{
    public function __invoke(Request $request)
    {
        validator($request->all(), [
            'source' => 'required|string|max:512', 'settings' => 'required|string|max:16384',
            'operation' => 'required|in:children,resolve', 'path' => 'sometimes|array|max:16',
            'path.*' => 'nullable|string|max:255', 'value' => 'nullable|string|max:255',
            'search' => 'nullable|string|max:200', 'cursor' => 'nullable|string|max:255',
        ])->validate();
        $sourceClass = $request->input('source');
        $settings = $request->input('settings');
        // Authorize before resolving any class supplied by the browser.
        ComponentSubmission::verify($request, 'cascader', $sourceClass, [$settings]);
        $settings = json_decode($settings, true, 32, JSON_THROW_ON_ERROR);
        $source = Source::make($sourceClass, $settings['context']);

        try {
            $path = Source::path($request->input('path', []));
            if ($request->input('operation') === 'resolve') {
                $value = $request->has('value') ? $request->input('value') : $path;
                $nodes = $source->validate($value, $settings['leafOnly'], $settings['depth']);

                return response()->json(['items' => $nodes]);
            }
            if ($path) {
                $source->validate($path, false, $settings['depth']);
            }
            if (count($path) >= ($settings['depth'] ?: 16)) {
                return response()->json(['items' => [], 'nextCursor' => null]);
            }
            $result = $source->children($path, $request->input('search') ?? '', $request->input('cursor'));
            $nodes = Source::nodes($result['items']);
            if (count($nodes) > 100 || count(array_unique(array_column($nodes, 'value'))) !== count($nodes)) {
                throw new InvalidArgumentException('Invalid child nodes.');
            }

            return response()->json(['items' => $nodes, 'nextCursor' => $result['nextCursor'] ?? null]);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => trans('admin::cascader.invalid')], 422);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['message' => trans('admin::cascader.failed')], 503);
        }
    }
}
