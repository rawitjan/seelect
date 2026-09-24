<?php

/** Export real Livewire-rendered states as deterministic video capture sources. */

use Illuminate\Contracts\Console\Kernel;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['contractors.ai_enabled' => false, 'session.driver' => 'array']);
session()->put('locale', 'ru');
app()->setLocale('ru');

$output = __DIR__.'/capture/states';
if (! is_dir($output)) {
    mkdir($output, 0755, true);
}

function exportScreen(Testable $component, string $name, string $selector, int $width = 1440, int $height = 1000): void
{
    global $output;

    $html = $component->html();
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xpath = new DOMXPath($document);
    foreach (['city', 'date', 'category', 'event_format', 'budget', 'hours', 'language', 'chatInput'] as $property) {
        $nodes = $xpath->query('//*[@*[name()="wire:model" or name()="wire:model.live"]="'.$property.'"]');
        foreach ($nodes as $node) {
            $value = (string) $component->get($property);
            if ($node->tagName === 'select') {
                foreach ($node->getElementsByTagName('option') as $option) {
                    if ($option->getAttribute('value') === $value) {
                        $option->setAttribute('selected', 'selected');
                    }
                }
            } elseif ($node->tagName === 'textarea') {
                $node->nodeValue = $value;
            } else {
                $node->setAttribute('value', $value);
            }
        }
    }
    foreach ($xpath->query('//img') as $image) {
        $image->setAttribute('loading', 'eager');
    }
    $html = $document->saveHTML();
    $css = file_get_contents(resource_path('css/finder.css'));
    $font = '@font-face{font-family:"IBM Plex Sans";src:url("data:font/ttf;base64,'.base64_encode(file_get_contents(__DIR__.'/assets/fonts/IBMPlexSans.ttf')).'");font-weight:100 900;font-style:normal}';
    $selection = $selector === 'hero' ? '.site-header,.hero' : $selector;
    $extra = $selector === '.detail-panel' ? '.detail-panel{position:absolute!important;inset:0!important;background:white!important;padding:20px!important}.detail-sheet{max-height:none!important;width:1360px!important}.detail-body{overflow:visible!important}' : '';
    $extra .= $selector === '.chat-panel' ? '.chat-panel{position:absolute!important;inset:32px!important;width:calc(100% - 64px)!important;height:calc(100% - 64px)!important;max-height:none!important;transform:none!important}' : '';
    $page = '<!doctype html><html lang="ru"><head><meta charset="utf-8"><style>'.$font.$css.'html,body{width:'.$width.'px;height:'.$height.'px;overflow:hidden;scroll-behavior:auto}.finder{position:relative}.finder>*,main>*{display:none!important}main{display:block!important}'.$selection.'{display:flex!important}.shell{width:calc(100% - 80px)!important;max-width:1320px}.brief-section,.results-section{padding:45px 0!important}.hero{min-height:800px!important}.results-section{display:block!important}.reveal{opacity:1!important;transform:none!important}[wire\\:loading]{display:none!important}*{animation:none!important;transition:none!important}'.$extra.'</style><script src="../../../assets/gsap.min.js"></script></head><body><div id="capture-root" data-composition-id="capture" data-width="'.$width.'" data-height="'.$height.'" data-duration="2">'.$html.'</div><script>window.__timelines={capture:gsap.timeline({paused:true})};</script></body></html>';
    $page = str_replace('main{display:block!important}', '.finder>main{display:block!important}', $page);
    $page = str_replace('#results-anchor{display:flex!important}', '#results-anchor{display:block!important}', $page);
    $page = str_replace('#finder-form{display:flex!important}', '#finder-form{display:grid!important}', $page);
    $page = str_replace('</style>', '[wire\\:dirty]{display:none!important}</style>', $page);
    $page = str_replace('<script src="../../../assets/gsap.min.js"></script>', '<script src="gsap.min.js"></script>', $page);
    $folder = $output.'/'.$name;
    if (! is_dir($folder)) {
        mkdir($folder, 0755, true);
    }
    file_put_contents($folder.'/index.html', $page);
    copy(__DIR__.'/assets/gsap.min.js', $folder.'/gsap.min.js');
    echo $name.PHP_EOL;
}

$finder = Livewire::test('pages::contractor-finder');
exportScreen($finder, 'hero', 'hero', 1440, 1000);
exportScreen($finder, 'form', '#finder-form', 1440, 900);
$finder->call('search');
exportScreen($finder, 'results', '#results-anchor', 1440, 1150);
$id = $finder->get('result.contractors.0.profile.id');
$finder->call('openContractorDetail', $id);
exportScreen($finder, 'profile', '.detail-panel', 1440, 1540);
$finder->call('closeContractorDetail')->set('date', '2026-09-24')->call('search');
exportScreen($finder, 'date', '#results-anchor', 1440, 1150);
$finder->set('category', 'Флорист')->set('budget', '1')->call('search');
exportScreen($finder, 'empty', '#results-anchor', 1440, 900);
$finder->call('openChat')->set('chatInput', 'Нужен ведущий на свадьбу в Алматы 23 сентября. Бюджет — 1 500 000 ₸.');
exportScreen($finder, 'chat', '.chat-panel', 1100, 960);
