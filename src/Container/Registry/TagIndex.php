<?php
declare(strict_types=1);

namespace Zara\Container\Registry;

final class TagIndex
{
    /** @var array<string, list<string>> */
    private array $tags = [];

    public function addTags(string $id, array $tags): void
    {
        foreach ($tags as $tag) {
            $tag = (string)$tag;
            if (!isset($this->tags[$tag])) {
                $this->tags[$tag] = [];
            }
            // Avoid duplicate tags for the same service.
            if (!in_array($id, $this->tags[$tag], true)) {
                $this->tags[$tag][] = $id;
            }
        }
    }

    /**
     * @return list<string>
     */
    public function getTagged(string $tag): array
    {
        return $this->tags[$tag] ?? [];
    }
}
