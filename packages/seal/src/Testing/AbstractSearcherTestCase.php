<?php

declare(strict_types=1);

/*
 * This file is part of the Schranz Search package.
 *
 * (c) Alexander Schranz <alexander@sulu.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Schranz\Search\SEAL\Testing;

use PHPUnit\Framework\TestCase;
use Schranz\Search\SEAL\Adapter\AdapterInterface;
use Schranz\Search\SEAL\Adapter\IndexerInterface;
use Schranz\Search\SEAL\Adapter\SchemaManagerInterface;
use Schranz\Search\SEAL\Adapter\SearcherInterface;
use Schranz\Search\SEAL\Schema\Schema;
use Schranz\Search\SEAL\Search\Condition;
use Schranz\Search\SEAL\Search\SearchBuilder;

abstract class AbstractSearcherTestCase extends TestCase
{
    protected static AdapterInterface $adapter;

    protected static SchemaManagerInterface $schemaManager;

    protected static IndexerInterface $indexer;

    protected static SearcherInterface $searcher;

    protected static Schema $schema;

    private static TaskHelper $taskHelper;

    protected function setUp(): void
    {
        self::$taskHelper = new TaskHelper();
    }

    public static function setUpBeforeClass(): void
    {
        self::$schemaManager = self::$adapter->getSchemaManager();
        self::$indexer = self::$adapter->getIndexer();
        self::$searcher = self::$adapter->getSearcher();

        self::$taskHelper = new TaskHelper();
        foreach (self::getSchema()->indexes as $index) {
            if (self::$schemaManager->existIndex($index)) {
                self::$schemaManager->dropIndex($index, ['return_slow_promise_result' => true])->wait();
            }

            self::$taskHelper->tasks[] = self::$schemaManager->createIndex($index, ['return_slow_promise_result' => true]);
        }

        self::$taskHelper->waitForAll();
    }

    public static function tearDownAfterClass(): void
    {
        self::$taskHelper->waitForAll();

        foreach (self::getSchema()->indexes as $index) {
            self::$taskHelper->tasks[] = self::$schemaManager->dropIndex($index, ['return_slow_promise_result' => true]);
        }

        self::$taskHelper->waitForAll();
    }

    protected static function getSchema(): Schema
    {
        if (!isset(self::$schema)) {
            self::$schema = TestingHelper::createSchema();
        }

        return self::$schema;
    }

    public function testFindMultipleIndexes(): void
    {
        $document = TestingHelper::createSimpleFixtures()[0];

        $schema = self::getSchema();

        self::$indexer->save(
            $schema->indexes[TestingHelper::INDEX_SIMPLE],
            $document,
            ['return_slow_promise_result' => true],
        )->wait();

        $search = new SearchBuilder($schema, self::$searcher);
        $search->addIndex(TestingHelper::INDEX_COMPLEX);
        $search->addIndex(TestingHelper::INDEX_SIMPLE);
        $search->addFilter(new Condition\IdentifierCondition($document['id']));

        $expectedDocument = $document;
        $loadedDocument = \iterator_to_array($search->getResult(), false)[0] ?? null;

        $this->assertSame($expectedDocument, $loadedDocument);

        self::$indexer->delete(
            $schema->indexes[TestingHelper::INDEX_SIMPLE],
            $document['id'],
            ['return_slow_promise_result' => true],
        )->wait();
    }

    public function testSearchCondition(): void
    {
        $documents = TestingHelper::createComplexFixtures();

        $schema = self::getSchema();

        foreach ($documents as $document) {
            self::$taskHelper->tasks[] = self::$indexer->save(
                $schema->indexes[TestingHelper::INDEX_COMPLEX],
                $document,
                ['return_slow_promise_result' => true],
            );
        }
        self::$taskHelper->waitForAll();

        $search = new SearchBuilder($schema, self::$searcher);
        $search->addIndex(TestingHelper::INDEX_COMPLEX);
        $search->addFilter(new Condition\SearchCondition('Blog'));

        $expectedDocumentsVariantA = [
            $documents[0],
            $documents[1],
        ];
        $expectedDocumentsVariantB = [
            $documents[1],
            $documents[0],
        ];

        $loadedDocuments = [...$search->getResult()];
        $this->assertCount(2, $loadedDocuments);

        $this->assertTrue(
            $expectedDocumentsVariantA === $loadedDocuments
            || $expectedDocumentsVariantB === $loadedDocuments,
            'Not correct documents where found.',
        );

        $search = new SearchBuilder($schema, self::$searcher);
        $search->addIndex(TestingHelper::INDEX_COMPLEX);
        $search->addFilter(new Condition\SearchCondition('Thing'));

        $this->assertSame([$documents[2]], [...$search->getResult()]);

        foreach ($documents as $document) {
            self::$taskHelper->tasks[] = self::$indexer->delete(
                $schema->indexes[TestingHelper::INDEX_COMPLEX],
                $document['uuid'],
                ['return_slow_promise_result' => true],
            );
        }
    }

    public function testNoneSearchableFields(): void
    {
        $documents = TestingHelper::createComplexFixtures();

        $schema = self::getSchema();

        foreach ($documents as $document) {
            self::$taskHelper->tasks[] = self::$indexer->save(
                $schema->indexes[TestingHelper::INDEX_COMPLEX],
                $document,
                ['return_slow_promise_result' => true],
            );
        }
        self::$taskHelper->waitForAll();

        $search = new SearchBuilder($schema, self::$searcher);
        $search->addIndex(TestingHelper::INDEX_COMPLEX);
        $search->addFilter(new Condition\SearchCondition('admin.nonesearchablefield@localhost'));

        $this->assertCount(0, [...$search->getResult()]);
    }

    public function testLimitAndOffset(): void
    {
        $documents = TestingHelper::createComplexFixtures();

        $schema = self::getSchema();

        foreach ($documents as $document) {
            self::$taskHelper->tasks[] = self::$indexer->save(
                $schema->indexes[TestingHelper::INDEX_COMPLEX],
                $document,
                ['return_slow_promise_result' => true],
            );
        }
        self::$taskHelper->waitForAll();

        $search = (new SearchBuilder($schema, self::$searcher))
            ->addIndex(TestingHelper::INDEX_COMPLEX)
            ->addFilter(new Condition\SearchCondition('Blog'))
            ->limit(1);

        $loadedDocuments = [...$search->getResult()];
        $this->assertCount(1, $loadedDocuments);

        $this->assertTrue(
            [$documents[0]] === $loadedDocuments
            || [$documents[1]] === $loadedDocuments,
            'Not correct documents where found.',
        );

        $isFirstDocumentOnPage1 = [$documents[0]] === $loadedDocuments;

        $search = (new SearchBuilder($schema, self::$searcher))
            ->addIndex(TestingHelper::INDEX_COMPLEX)
            ->addFilter(new Condition\SearchCondition('Blog'))
            ->offset(1)
            ->limit(1);

        $loadedDocuments = [...$search->getResult()];
        $this->assertCount(1, $loadedDocuments);
        $this->assertSame(
            $isFirstDocumentOnPage1 ? [$documents[1]] : [$documents[0]],
            $loadedDocuments,
        );

        foreach ($documents as $document) {
            self::$taskHelper->tasks[] = self::$indexer->delete(
                $schema->indexes[TestingHelper::INDEX_COMPLEX],
                $document['uuid'],
                ['return_slow_promise_result' => true],
            );
        }
    }

    public function testEqualCondition(): void
    {
        $documents = TestingHelper::createComplexFixtures();

        $schema = self::getSchema();

        foreach ($documents as $document) {
            self::$taskHelper->tasks[] = self::$indexer->save(
                $schema->indexes[TestingHelper::INDEX_COMPLEX],
                $document,
                ['return_slow_promise_result' => true],
            );
        }
        self::$taskHelper->waitForAll();

        $search = new SearchBuilder($schema, self::$searcher);
        $search->addIndex(TestingHelper::INDEX_COMPLEX);
        $search->addFilter(new Condition\EqualCondition('tags', 'UI'));

        $expectedDocumentsVariantA = [
            $documents[0],
            $documents[1],
        ];
        $expectedDocumentsVariantB = [
            $documents[1],
            $documents[0],
        ];

        $loadedDocuments = [...$search->getResult()];
        $this->assertCount(2, $loadedDocuments);

        $this->assertTrue(
            $expectedDocumentsVariantA === $loadedDocuments
            || $expectedDocumentsVariantB === $loadedDocuments,
            'Not correct documents where found.',
        );

        foreach ($documents as $document) {
            self::$taskHelper->tasks[] = self::$indexer->delete(
                $schema->indexes[TestingHelper::INDEX_COMPLEX],
                $document['uuid'],
                ['return_slow_promise_result' => true],
            );
        }
    }

    public function testMultiEqualCondition(): void
    {
        $documents = TestingHelper::createComplexFixtures();

        $schema = self::getSchema();

        foreach ($documents as $document) {
            self::$taskHelper->tasks[] = self::$indexer->save(
                $schema->indexes[TestingHelper::INDEX_COMPLEX],
                $document,
                ['return_slow_promise_result' => true],
            );
        }
        self::$taskHelper->waitForAll();

        $search = new SearchBuilder($schema, self::$searcher);
        $search->addIndex(TestingHelper::INDEX_COMPLEX);
        $search->addFilter(new Condition\EqualCondition('tags', 'UI'));
        $search->addFilter(new Condition\EqualCondition('tags', 'UX'));

        $loadedDocuments = [...$search->getResult()];
        $this->assertCount(1, $loadedDocuments);

        $this->assertSame(
            [$documents[1]],
            $loadedDocuments,
        );

        foreach ($documents as $document) {
            self::$taskHelper->tasks[] = self::$indexer->delete(
                $schema->indexes[TestingHelper::INDEX_COMPLEX],
                $document['uuid'],
                ['return_slow_promise_result' => true],
            );
        }
    }

    public function testNotEqualCondition(): void
    {
        $documents = TestingHelper::createComplexFixtures();

        $schema = self::getSchema();

        foreach ($documents as $document) {
            self::$taskHelper->tasks[] = self::$indexer->save(
                $schema->indexes[TestingHelper::INDEX_COMPLEX],
                $document,
                ['return_slow_promise_result' => true],
            );
        }
        self::$taskHelper->waitForAll();

        $search = new SearchBuilder($schema, self::$searcher);
        $search->addIndex(TestingHelper::INDEX_COMPLEX);
        $search->addFilter(new Condition\NotEqualCondition('tags', 'UI'));

        $expectedDocumentsVariantA = [
            $documents[2],
            $documents[3],
        ];
        $expectedDocumentsVariantB = [
            $documents[3],
            $documents[2],
        ];

        $loadedDocuments = [...$search->getResult()];
        $this->assertCount(2, $loadedDocuments);

        $this->assertTrue(
            $expectedDocumentsVariantA === $loadedDocuments
            || $expectedDocumentsVariantB === $loadedDocuments,
            'Not correct documents where found.',
        );

        foreach ($documents as $document) {
            self::$taskHelper->tasks[] = self::$indexer->delete(
                $schema->indexes[TestingHelper::INDEX_COMPLEX],
                $document['uuid'],
                ['return_slow_promise_result' => true],
            );
        }
    }

    public function testGreaterThanCondition(): void
    {
        $documents = TestingHelper::createComplexFixtures();

        $schema = self::getSchema();

        foreach ($documents as $document) {
            self::$taskHelper->tasks[] = self::$indexer->save(
                $schema->indexes[TestingHelper::INDEX_COMPLEX],
                $document,
                ['return_slow_promise_result' => true],
            );
        }
        self::$taskHelper->waitForAll();

        $search = new SearchBuilder($schema, self::$searcher);
        $search->addIndex(TestingHelper::INDEX_COMPLEX);
        $search->addFilter(new Condition\GreaterThanCondition('rating', 2.5));

        $loadedDocuments = [...$search->getResult()];
        $this->assertGreaterThanOrEqual(1, \count($loadedDocuments));

        foreach ($loadedDocuments as $loadedDocument) {
            $this->assertGreaterThan(2.5, $loadedDocument['rating']);
        }

        foreach ($documents as $document) {
            self::$taskHelper->tasks[] = self::$indexer->delete(
                $schema->indexes[TestingHelper::INDEX_COMPLEX],
                $document['uuid'],
                ['return_slow_promise_result' => true],
            );
        }
    }

    public function testGreaterThanEqualCondition(): void
    {
        $documents = TestingHelper::createComplexFixtures();

        $schema = self::getSchema();

        foreach ($documents as $document) {
            self::$taskHelper->tasks[] = self::$indexer->save(
                $schema->indexes[TestingHelper::INDEX_COMPLEX],
                $document,
                ['return_slow_promise_result' => true],
            );
        }
        self::$taskHelper->waitForAll();

        $search = new SearchBuilder($schema, self::$searcher);
        $search->addIndex(TestingHelper::INDEX_COMPLEX);
        $search->addFilter(new Condition\GreaterThanEqualCondition('rating', 2.5));

        $loadedDocuments = [...$search->getResult()];
        $this->assertGreaterThan(1, \count($loadedDocuments));

        foreach ($loadedDocuments as $loadedDocument) {
            $this->assertNotNull(
                $loadedDocument['rating'] ?? null,
                'Expected only documents with rating document "' . $loadedDocument['uuid'] . '" without rating returned.',
            );

            $this->assertGreaterThanOrEqual(2.5, $loadedDocument['rating']);
        }

        foreach ($documents as $document) {
            self::$taskHelper->tasks[] = self::$indexer->delete(
                $schema->indexes[TestingHelper::INDEX_COMPLEX],
                $document['uuid'],
                ['return_slow_promise_result' => true],
            );
        }
    }

    public function testLessThanCondition(): void
    {
        $documents = TestingHelper::createComplexFixtures();

        $schema = self::getSchema();

        foreach ($documents as $document) {
            self::$taskHelper->tasks[] = self::$indexer->save(
                $schema->indexes[TestingHelper::INDEX_COMPLEX],
                $document,
                ['return_slow_promise_result' => true],
            );
        }
        self::$taskHelper->waitForAll();

        $search = new SearchBuilder($schema, self::$searcher);
        $search->addIndex(TestingHelper::INDEX_COMPLEX);
        $search->addFilter(new Condition\LessThanCondition('rating', 3.5));

        $loadedDocuments = [...$search->getResult()];
        $this->assertGreaterThanOrEqual(1, \count($loadedDocuments));

        foreach ($loadedDocuments as $loadedDocument) {
            $this->assertNotNull(
                $loadedDocument['rating'] ?? null,
                'Expected only documents with rating document "' . $loadedDocument['uuid'] . '" without rating returned.',
            );

            $this->assertLessThan(3.5, $loadedDocument['rating']);
        }

        foreach ($documents as $document) {
            self::$taskHelper->tasks[] = self::$indexer->delete(
                $schema->indexes[TestingHelper::INDEX_COMPLEX],
                $document['uuid'],
                ['return_slow_promise_result' => true],
            );
        }
    }

    public function testLessThanEqualCondition(): void
    {
        $documents = TestingHelper::createComplexFixtures();

        $schema = self::getSchema();

        foreach ($documents as $document) {
            self::$taskHelper->tasks[] = self::$indexer->save(
                $schema->indexes[TestingHelper::INDEX_COMPLEX],
                $document,
                ['return_slow_promise_result' => true],
            );
        }
        self::$taskHelper->waitForAll();

        $search = new SearchBuilder($schema, self::$searcher);
        $search->addIndex(TestingHelper::INDEX_COMPLEX);
        $search->addFilter(new Condition\LessThanEqualCondition('rating', 3.5));

        $loadedDocuments = [...$search->getResult()];
        $this->assertGreaterThan(1, \count($loadedDocuments));

        foreach ($loadedDocuments as $loadedDocument) {
            $this->assertNotNull(
                $loadedDocument['rating'] ?? null,
                'Expected only documents with rating document "' . $loadedDocument['uuid'] . '" without rating returned.',
            );

            $this->assertLessThanOrEqual(3.5, $loadedDocument['rating']);
        }

        foreach ($documents as $document) {
            self::$taskHelper->tasks[] = self::$indexer->delete(
                $schema->indexes[TestingHelper::INDEX_COMPLEX],
                $document['uuid'],
                ['return_slow_promise_result' => true],
            );
        }
    }

    public function testSortByAsc(): void
    {
        $documents = TestingHelper::createComplexFixtures();

        $schema = self::getSchema();

        foreach ($documents as $document) {
            self::$taskHelper->tasks[] = self::$indexer->save(
                $schema->indexes[TestingHelper::INDEX_COMPLEX],
                $document,
                ['return_slow_promise_result' => true],
            );
        }
        self::$taskHelper->waitForAll();

        $search = new SearchBuilder($schema, self::$searcher);
        $search->addIndex(TestingHelper::INDEX_COMPLEX);
        $search->addFilter(new Condition\GreaterThanCondition('rating', 0));
        $search->addSortBy('rating', 'asc');

        $loadedDocuments = [...$search->getResult()];
        $this->assertGreaterThan(1, \count($loadedDocuments));

        foreach ($documents as $document) {
            self::$taskHelper->tasks[] = self::$indexer->delete(
                $schema->indexes[TestingHelper::INDEX_COMPLEX],
                $document['uuid'],
                ['return_slow_promise_result' => true],
            );
        }

        $beforeRating = 0;
        foreach ($loadedDocuments as $loadedDocument) {
            $rating = $loadedDocument['rating'] ?? 0;
            $this->assertGreaterThanOrEqual($beforeRating, $rating);
            $beforeRating = $rating;
        }
    }

    public function testSortByDesc(): void
    {
        $documents = TestingHelper::createComplexFixtures();

        $schema = self::getSchema();

        foreach ($documents as $document) {
            self::$taskHelper->tasks[] = self::$indexer->save(
                $schema->indexes[TestingHelper::INDEX_COMPLEX],
                $document,
                ['return_slow_promise_result' => true],
            );
        }
        self::$taskHelper->waitForAll();

        $search = new SearchBuilder($schema, self::$searcher);
        $search->addIndex(TestingHelper::INDEX_COMPLEX);
        $search->addFilter(new Condition\GreaterThanCondition('rating', 0));
        $search->addSortBy('rating', 'desc');

        $loadedDocuments = [...$search->getResult()];
        $this->assertGreaterThan(1, \count($loadedDocuments));

        foreach ($documents as $document) {
            self::$taskHelper->tasks[] = self::$indexer->delete(
                $schema->indexes[TestingHelper::INDEX_COMPLEX],
                $document['uuid'],
                ['return_slow_promise_result' => true],
            );
        }
        $beforeRating = \PHP_INT_MAX;
        foreach ($loadedDocuments as $loadedDocument) {
            $rating = $loadedDocument['rating'] ?? 0;
            $this->assertLessThanOrEqual($beforeRating, $rating);
            $beforeRating = $rating;
        }
    }
}
