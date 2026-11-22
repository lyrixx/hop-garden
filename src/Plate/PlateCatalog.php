<?php

namespace App\Plate;

use App\Plate\Model\Plate;
use App\Plate\Model\PlatePart;
use App\Plate\Model\PlatePoint;

/**
 * The plates of the Biodiversity Heritage Library cleaned for Hop Garden (assets/images/plates/, public domain mark).
 * A plant gets the plate of its species, else one of its genus, else the schematic figure.
 */
final readonly class PlateCatalog
{
    public function forSpecies(string $scientificName): Plate
    {
        $words = array_values(array_filter(
            preg_split('/\s+/u', mb_strtolower(trim($scientificName))) ?: [],
            static fn (string $word): bool => !\in_array($word, ['×', 'x', ''], true),
        ));
        $species = implode(' ', \array_slice($words, 0, 2));
        $genus = $words[0] ?? '';

        $plates = self::plates();
        foreach ($plates as $plate) {
            if (\in_array($species, $plate->species, true)) {
                return $plate;
            }
        }
        foreach ($plates as $plate) {
            if ($genus === $plate->genus()) {
                return $plate;
            }
        }

        return Plate::schematicFigure('images/plates/schematic.svg', 600, 800, [
            PlatePart::Bud->value => new PlatePoint(61, 17),
            PlatePart::Leaf->value => new PlatePoint(36, 50),
            PlatePart::Stem->value => new PlatePoint(49, 70),
            PlatePart::Flower->value => new PlatePoint(45, 15),
            PlatePart::Shoot->value => new PlatePoint(70, 47),
        ]);
    }

    /**
     * Titles, places and dates as the Flickr record of each plate gives them.
     *
     * @return list<Plate>
     */
    private static function plates(): array
    {
        return [
            Plate::fromLibrary(
                'images/plates/rosa-canina.webp', 1194, 1600,
                ['rosa canina'],
                'Rosa canina L.', 'Deutschlands Flora in Abbildungen nach der Natur', 'Nürnberg, 1798–1862',
                'https://www.flickr.com/photos/biodivlibrary/50902108793/',
                self::anchors(bud: [39, 5], leaf: [31, 27], stem: [57, 52], flower: [47, 16], shoot: [67, 46]),
            ),
            Plate::fromLibrary(
                'images/plates/citrus-limon.webp', 1420, 1600,
                ['citrus limon', 'citrus limonum'],
                'Citrus limonum Risso', 'Köhler’s Medizinal-Pflanzen', 'Gera-Untermhaus, 1883–1914',
                'https://www.flickr.com/photos/biodivlibrary/6972229396/',
                self::anchors(bud: [47.2, 25.7], leaf: [22.2, 40.7], stem: [47.2, 65], flower: [54.6, 22], shoot: [44.4, 35]),
            ),
            Plate::fromLibrary(
                'images/plates/hydrangea-paniculata.webp', 1035, 1600,
                ['hydrangea paniculata'],
                'Hydrangea paniculata grandiflora', 'Revue horticole', 'Paris, 1829–1974',
                'https://www.flickr.com/photos/biodivlibrary/31749205587/',
                self::anchors(bud: [48, 2.5], leaf: [25.3, 71], stem: [39.8, 84], flower: [42.9, 43], shoot: [40.8, 65]),
            ),
            Plate::fromLibrary(
                'images/plates/hydrangea-macrophylla.webp', 1165, 1600,
                ['hydrangea macrophylla', 'hydrangea hortensia'],
                'Hydrangea hortensia', 'Favourite flowers of garden and greenhouse', 'London and New York, 1896–97',
                'https://www.flickr.com/photos/biodivlibrary/10574939953/',
                self::anchors(bud: [57.6, 50], leaf: [22.6, 64.4], stem: [46.2, 87.4], flower: [47.2, 44.2], shoot: [49.1, 32.7]),
            ),
            Plate::fromLibrary(
                'images/plates/lavandula.webp', 1267, 1600,
                ['lavandula angustifolia', 'lavandula vera', 'lavandula officinalis'],
                'Lavandula vera D.C.', 'Köhler’s Medizinal-Pflanzen', 'Gera-Untermhaus, 1883–1914',
                'https://www.flickr.com/photos/biodivlibrary/6972249268/',
                self::anchors(bud: [50, 5.4], leaf: [37.5, 57.8], stem: [51.9, 89.8], flower: [64.4, 17], shoot: [46.2, 40.3]),
            ),
        ];
    }

    /**
     * @param array{float, float} $bud
     * @param array{float, float} $leaf
     * @param array{float, float} $stem
     * @param array{float, float} $flower
     * @param array{float, float} $shoot
     *
     * @return array<string, PlatePoint>
     */
    private static function anchors(array $bud, array $leaf, array $stem, array $flower, array $shoot): array
    {
        return [
            PlatePart::Bud->value => new PlatePoint(...$bud),
            PlatePart::Leaf->value => new PlatePoint(...$leaf),
            PlatePart::Stem->value => new PlatePoint(...$stem),
            PlatePart::Flower->value => new PlatePoint(...$flower),
            PlatePart::Shoot->value => new PlatePoint(...$shoot),
        ];
    }
}
