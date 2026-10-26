<?php

namespace Encore\Admin\LogViewer;

use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class LogController extends Controller
{
    private function viewer(Request $request, ?string $file = null): LogViewer
    {
        $request->validate([
            'type' => 'nullable|string|max:64',
            'offset' => 'nullable|integer|between:'.(-PHP_INT_MAX).','.PHP_INT_MAX,
        ]);

        try {
            $viewer = new LogViewer($file, $request->input('type'));
        } catch (\InvalidArgumentException $exception) {
            abort(404);
        }

        abort_if($file !== null && $viewer->getFilePath() === '', 404);

        return $viewer;
    }

    public function index(Request $request, Content $content, ?string $file = null)
    {
        $viewer = $this->viewer($request, $file);
        // Capture the cursor before reading so entries appended during the read
        // are still picked up by the first live poll.
        $end = $viewer->getFilesize();
        $logs = $viewer->fetch((int) $request->input('offset', 0));

        return $content->header(trans('admin.log_viewer.title'))->body(view('admin::log-viewer.logs', [
            'logs' => $logs,
            'logFiles' => $viewer->getLogFiles(),
            'file' => $viewer->file,
            'type' => $viewer->type,
            'directories' => array_keys(LogViewer::directories()),
            'end' => $end,
            'tailPath' => $viewer->file === '' ? '' : route('log-viewer-tail', ['file' => $viewer->file, 'type' => $viewer->type]),
            'prevUrl' => $viewer->getPrevPageUrl(),
            'nextUrl' => $viewer->getNextPageUrl(),
            'size' => static::bytesToHuman($end),
        ]));
    }

    public function delete(Request $request, string $file)
    {
        $viewer = $this->viewer($request, $file);
        $deleted = $viewer->deleteLogFile();
        admin_toastr(trans($deleted ? 'admin.delete_succeeded' : 'admin.delete_failed'), $deleted ? 'success' : 'error');

        return redirect()->route('log-viewer-index', ['type' => $viewer->type]);
    }

    public function deleteAll(Request $request)
    {
        $viewer = $this->viewer($request);
        $viewer->deleteAll();
        admin_toastr(trans('admin.delete_succeeded'));

        return redirect()->route('log-viewer-index', ['type' => $viewer->type]);
    }

    public function tail(Request $request, string $file)
    {
        $viewer = $this->viewer($request, $file);
        [$pos, $logs] = $viewer->tail((int) $request->input('offset', 0));

        return response()->json(compact('pos', 'logs'));
    }

    protected static function bytesToHuman($bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $index = 0;
        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }

        return round($bytes, 2).' '.$units[$index];
    }
}
