<?php

/**
 * @file tests/SimilarityFinderTest.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SimilarityFinderTest
 *
 * @brief Unit tests for the term extraction.
 *
 * The query itself needs a populated search index and is covered by
 * tests/regression.php; what is tested here is the contract that keeps this
 * plugin equivalent to the original — that the terms come from the core's own
 * extractor and are capped at the same number.
 *
 *   lib/pkp/lib/vendor/bin/phpunit plugins/generic/recommendBySimilarity/tests
 */

namespace APP\plugins\generic\recommendBySimilarity\tests;

use APP\plugins\generic\recommendBySimilarity\classes\SimilarityFinder;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\tests\PKPTestCase;

#[CoversClass(SimilarityFinder::class)]
class SimilarityFinderTest extends PKPTestCase
{
    private SimilarityFinder $finder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finder = new SimilarityFinder();
    }

    public function testAnEmptyPhraseYieldsNoTerms(): void
    {
        $this->assertSame([], $this->finder->terms(''));
    }

    public function testTermsAreLowercasedLikeTheIndex(): void
    {
        foreach ($this->finder->terms('Metodologias ATIVAS Docente') as $term) {
            $this->assertSame(mb_strtolower($term, 'UTF-8'), $term);
        }
    }

    public function testTermsAreDeduplicated(): void
    {
        $terms = $this->finder->terms('docente docente docente formação');
        $this->assertSame(array_values(array_unique($terms)), $terms);
    }

    public function testTheTermCapMatchesTheOriginalPlugin(): void
    {
        $this->assertSame(20, SimilarityFinder::MAX_SEARCH_KEYWORDS);

        $words = [];
        for ($i = 0; $i < 60; $i++) {
            $words[] = 'palavra' . $i;
        }

        $this->assertLessThanOrEqual(
            SimilarityFinder::MAX_SEARCH_KEYWORDS,
            count($this->finder->terms(implode(' ', $words)))
        );
    }

    public function testNoTermsMeansNoQueryAndNoResults(): void
    {
        // Guards the early return: with no terms the finder must not reach the
        // database at all, so this is safe to assert without a fixture.
        $this->assertSame([], $this->finder->find(1, 1, [], 10));
    }
}
