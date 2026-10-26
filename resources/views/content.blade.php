@extends('admin::index', ['header' => strip_tags($header)])

@section('content')
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-sm-6">
                    <h3 class="mb-0">{{ $header ?: trans('admin.title') }}</h3>
                    @if($description)
                        <p class="text-body-secondary mb-0">{{ $description }}</p>
                    @endif
                </div>
                <div class="col-sm-6">
                    @if ($breadcrumb)
                        <ol class="breadcrumb float-sm-end mb-0">
                            <li class="breadcrumb-item">
                                <a href="{{ admin_url('/') }}"><i class="fa fa-dashboard" aria-hidden="true"></i> {{ __('Home') }}</a>
                            </li>
                            @foreach($breadcrumb as $item)
                                @if($loop->last)
                                    <li class="breadcrumb-item active" aria-current="page">
                                        @if (\Illuminate\Support\Arr::has($item, 'icon'))
                                            <i class="fa fa-{{ $item['icon'] }}" aria-hidden="true"></i>
                                        @endif
                                        {{ $item['text'] }}
                                    </li>
                                @else
                                    <li class="breadcrumb-item">
                                        @if (\Illuminate\Support\Arr::has($item, 'url'))
                                            <a href="{{ admin_url(\Illuminate\Support\Arr::get($item, 'url')) }}">
                                                @if (\Illuminate\Support\Arr::has($item, 'icon'))
                                                    <i class="fa fa-{{ $item['icon'] }}" aria-hidden="true"></i>
                                                @endif
                                                {{ $item['text'] }}
                                            </a>
                                        @else
                                            @if (\Illuminate\Support\Arr::has($item, 'icon'))
                                                <i class="fa fa-{{ $item['icon'] }}" aria-hidden="true"></i>
                                            @endif
                                            {{ $item['text'] }}
                                        @endif
                                    </li>
                                @endif
                            @endforeach
                        </ol>
                    @elseif(config('admin.enable_default_breadcrumb'))
                        <ol class="breadcrumb float-sm-end mb-0">
                            <li class="breadcrumb-item">
                                <a href="{{ admin_url('/') }}"><i class="fa fa-dashboard" aria-hidden="true"></i> {{ __('Home') }}</a>
                            </li>
                            @for($i = 2; $i <= count(Request::segments()); $i++)
                                <li class="breadcrumb-item">{{ ucfirst(Request::segment($i)) }}</li>
                            @endfor
                        </ol>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">
            @include('admin::partials.alerts')
            @include('admin::partials.exception')
            @include('admin::partials.toastr')

            @if($_view_)
                @include($_view_['view'], $_view_['data'])
            @else
                {!! $_content_ !!}
            @endif
        </div>
    </div>
@endsection
