<?php

namespace App\Plate\Model;

/**
 * The illustration of a plant page: a hand-coloured plate of the Biodiversity Heritage Library, or the schematic
 * figure drawn for the species the library has no plate for. Its anchors say where each part of the plant is drawn.
 */
final readonly class Plate
{
    /** Vertical room left between two figure numbers in a margin, in percent of the image height */
    private const float NUMBER_GAP = 6.5;

    /**
     * @param list<string>              $species "genus species" names the plate stands for, in lowercase
     * @param array<string, PlatePoint> $anchors keyed by PlatePart values
     */
    private function __construct(
        public string $image,
        public int $width,
        public int $height,
        public array $species,
        public ?string $depicts,
        public ?string $work,
        public ?string $published,
        public ?string $url,
        private array $anchors,
    ) {
        foreach (PlatePart::cases() as $part) {
            if (!isset($anchors[$part->value])) {
                throw new \LogicException(\sprintf('The plate "%s" has no anchor for the %s.', $image, $part->value));
            }
        }
    }

    /**
     * @param list<string>              $species
     * @param array<string, PlatePoint> $anchors
     */
    public static function fromLibrary(string $image, int $width, int $height, array $species, string $depicts, string $work, string $published, string $url, array $anchors): self
    {
        return new self($image, $width, $height, $species, $depicts, $work, $published, $url, $anchors);
    }

    /**
     * @param array<string, PlatePoint> $anchors
     */
    public static function schematicFigure(string $image, int $width, int $height, array $anchors): self
    {
        return new self($image, $width, $height, [], null, null, null, null, $anchors);
    }

    public function isSchematic(): bool
    {
        return null === $this->url;
    }

    public function genus(): ?string
    {
        return [] !== $this->species ? explode(' ', $this->species[0])[0] : null;
    }

    public function anchor(PlatePart $part): PlatePoint
    {
        return $this->anchors[$part->value];
    }

    /**
     * The parts drawn on the left half get their number in the left margin, the others in the right one; the
     * figures are numbered in reading order, down the left margin then down the right one.
     *
     * @return list<PlateFigure>
     */
    public function figures(): array
    {
        $parts = PlatePart::cases();
        usort($parts, fn (PlatePart $a, PlatePart $b): int => $this->anchor($a)->x <=> $this->anchor($b)->x);
        $half = (int) ceil(\count($parts) / 2);

        $figures = [];
        $number = 1;
        foreach ([[PlateSide::Left, \array_slice($parts, 0, $half)], [PlateSide::Right, \array_slice($parts, $half)]] as [$side, $sideParts]) {
            usort($sideParts, fn (PlatePart $a, PlatePart $b): int => $this->anchor($a)->y <=> $this->anchor($b)->y);
            $numberYs = self::spread(array_map(fn (PlatePart $part): float => $this->anchor($part)->y, $sideParts));
            foreach ($sideParts as $i => $part) {
                $figures[] = new PlateFigure($number++, $part, $this->anchor($part), $side, $numberYs[$i]);
            }
        }

        return $figures;
    }

    /**
     * Moves sorted positions apart so that two numbers never overlap, keeping them inside the image.
     *
     * @param list<float> $ys
     *
     * @return list<float>
     */
    private static function spread(array $ys): array
    {
        // Downwards, each number at least one gap below the previous one; then upwards, from the bottom edge
        $down = [];
        $previous = 4.0 - self::NUMBER_GAP;
        foreach ($ys as $y) {
            $down[] = $previous = max($y, $previous + self::NUMBER_GAP);
        }

        $up = [];
        $next = 96.0 + self::NUMBER_GAP;
        foreach (array_reverse($down) as $y) {
            $up[] = $next = min($y, $next - self::NUMBER_GAP);
        }

        return array_reverse($up);
    }
}
