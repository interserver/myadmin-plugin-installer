<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing\Fakes;

use MyAdmin\Plugins\Testing\CallLog;
use MyAdmin\Plugins\Testing\Recorder;

/**
 * Stand-in for `\MyAdmin\Menu`, the subject handed to `getMenu()`.
 *
 * Signatures are lifted verbatim from `include/Menu.php`; the stored shape
 * mirrors core's so assertions read the same structure the real class builds.
 * Fleet call frequency: `add_link` 117 · `add_menu` 10.
 *
 * `add_external_link()` is included even though the fleet does not currently
 * call it, so the first plugin that does needs no harness change.
 */
class FakeMenu
{
    use Recorder;

    /**
     * category => list of entries, exactly as core stores them.
     *
     * @var array<string,array<int,array<string,mixed>>>
     */
    private $menu = [];

    /**
     * @param \MyAdmin\Plugins\Testing\CallLog|null $log
     */
    public function __construct(?CallLog $log = null)
    {
        $this->initRecorder($log);
    }

    /**
     * @param string            $category
     * @param string            $params
     * @param string            $image
     * @param string            $text
     * @param bool|false|string $target
     * @param array|bool        $link_names
     * @return void
     */
    public function add_link($category, $params, $image, $text, $target = false, $link_names = [])
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->menu[$category][] = [
            'type'       => 'link',
            'params'     => $params,
            'image'      => $image,
            'text'       => $text,
            'target'     => $target,
            'link_names' => $link_names,
        ];
    }

    /**
     * @param string $category
     * @param string $url
     * @param string $image
     * @param string $text
     * @return void
     */
    public function add_external_link($category, $url, $image, $text)
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->menu[$category][] = [
            'type'  => 'external_link',
            'url'   => $url,
            'image' => $image,
            'text'  => $text,
        ];
    }

    /**
     * @param string $parent
     * @param string $category
     * @param string $text
     * @param string $image
     * @param array  $link_names
     * @return void
     */
    public function add_menu($parent, $category, $text, $image = '', $link_names = [])
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->menu[$parent][] = [
            'type'       => 'menu',
            'name'       => $category,
            'text'       => $text,
            'image'      => $image,
            'link_names' => $link_names,
        ];
    }

    // -----------------------------------------------------------------------
    // Test-facing readers (D5)
    // -----------------------------------------------------------------------

    /**
     * Every entry added, flattened, in order.
     *
     * @return array<int,array<string,mixed>>
     */
    public function links()
    {
        $flat = [];
        foreach ($this->menu as $category => $entries) {
            foreach ($entries as $entry) {
                $entry['category'] = $category;
                $flat[] = $entry;
            }
        }
        return $flat;
    }

    /**
     * The raw category => entries map, as core stores it.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    public function menu()
    {
        return $this->menu;
    }

    /**
     * Category names that received at least one entry.
     *
     * @return array<int,string>
     */
    public function categories()
    {
        return array_keys($this->menu);
    }

    /**
     * The link texts added, in order.
     *
     * @return array<int,string>
     */
    public function linkTexts()
    {
        return array_map(static function (array $entry) {
            return isset($entry['text']) ? (string)$entry['text'] : '';
        }, $this->links());
    }

    /**
     * @param string $text
     * @return bool
     */
    public function hasLinkText($text)
    {
        return in_array($text, $this->linkTexts(), true);
    }

    /**
     * @return int
     */
    public function linkCount()
    {
        return count($this->links());
    }

    /**
     * @return void
     */
    public function reset()
    {
        $this->menu = [];
        $this->resetCalls();
    }
}
