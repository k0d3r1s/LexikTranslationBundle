<?php

namespace Lexik\Bundle\TranslationBundle\Entity;

use Lexik\Bundle\TranslationBundle\Util\Doctrine\SingleColumnArrayHydrator;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\EntityRepository;
use Lexik\Bundle\TranslationBundle\Model\File as ModelFile;

/**
 * Repository for TransUnit entity.
 *
 * @author Cédric Girard <c.girard@lexik.fr>
 */
class TransUnitRepository extends EntityRepository
{
    /**
     * Returns all domain available in database.
     *
     * @return array
     */
    public function getAllDomainsByLocale()
    {
        return $this->createQueryBuilder('tu')
            ->select('te.locale, tu.domain')
            ->leftJoin('tu.translations', 'te')
            ->where('te.id is not null')
            ->addGroupBy('te.locale')
            ->addGroupBy('tu.domain')
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * Returns all domains for each locale.
     *
     * @return array
     */
    public function getAllByLocaleAndDomain($locale, $domain): mixed
    {
        return $this->createQueryBuilder('tu')
            ->select('tu, te')
            ->leftJoin('tu.translations', 'te')
            ->where('tu.domain = :domain')
            ->andWhere('te.locale = :locale')
            ->setParameter('domain', $domain)
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * Returns all trans unit with translations for the given domain and locale.
     *
     * @return array
     */
    public function getAllDomains(): mixed
    {
        $this->loadCustomHydrator();

        return $this->createQueryBuilder('tu')
            ->select('DISTINCT tu.domain')
            ->orderBy('tu.domain', 'ASC')
            ->getQuery()
            ->getResult('SingleColumnArrayHydrator');
    }

    /**
     * Returns some trans units with their translations.
     *
     * @param int   $rows
     * @param int   $page
     * @return array
     */
    public function getTransUnitList(?array $locales = null, $rows = 20, $page = 1, ?array $filters = null): mixed
    {
        $this->loadCustomHydrator();

        $sortColumn = $filters['sidx'] ?? 'id';
        $order = $filters['sord'] ?? 'ASC';

        $builder = $this->createQueryBuilder('tu')
            ->select('tu.id');

        $this->addTransUnitFilters($builder, $filters);
        $this->addTranslationFilter($builder, $locales, $filters);

        $ids = $builder->orderBy(sprintf('tu.%s', $sortColumn), $order)
            ->setFirstResult($rows * ($page - 1))
            ->setMaxResults($rows)
            ->getQuery()
            ->getResult('SingleColumnArrayHydrator');

        $transUnits = [];

        if ((is_countable($ids) ? count($ids) : 0) > 0) {
            $qb = $this->createQueryBuilder('tu');

            $transUnits = $qb->select('tu, te')
                ->leftJoin('tu.translations', 'te')
                ->andWhere($qb->expr()->in('tu.id', $ids))
                ->andWhere($qb->expr()->in('te.locale', $locales))
                ->orderBy(sprintf('tu.%s', $sortColumn), $order)
                ->getQuery()
                ->getArrayResult();
        }

        return $transUnits;
    }

    /**
     * Count the number of trans unit.
     * @param array<string, mixed> $criteria
     * @return int
     */
    public function count(array $criteria = []): int
    {
        $this->loadCustomHydrator();

        $builder = $this->createQueryBuilder('tu')
            ->select('COUNT(DISTINCT tu.id) AS number');

        $locales = $criteria['locales'] ?? null;
        $filters = $criteria['filters'] ?? null;

        $this->addTransUnitFilters($builder, $filters);
        $this->addTranslationFilter($builder, $locales, $filters);

        return (int) $builder->getQuery()->getResult(Query::HYDRATE_SINGLE_SCALAR);
    }

    /**
     * @return array
     */
    public function countByDomains(): mixed
    {
        return $this->createQueryBuilder('tu')
            ->select('COUNT(DISTINCT tu.id) AS number, tu.domain')
            ->groupBy('tu.domain')
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * Returns all translations for the given file.
     *
     * @param boolean   $onlyUpdated
     * @return array
     */
    public function getTranslationsForFile(ModelFile $file, $onlyUpdated): array
    {
        $builder = $this->createQueryBuilder('tu')
            ->select('tu.key, te.content')
            ->leftJoin('tu.translations', 'te')
            ->where('te.file = :file')
            ->setParameter('file', $file->getId())
            ->orderBy('te.id', 'asc');

        if ($onlyUpdated) {
            $builder->andWhere($builder->expr()->gt('te.updatedAt', 'te.createdAt'));
        }

        $results = $builder->getQuery()->getArrayResult();

        $translations = [];
        foreach ($results as $result) {
            $translations[$result['key']] = $result['content'];
        }

        return $translations;
    }

    /**
     * Add conditions according to given filters.
     */
    protected function addTransUnitFilters(QueryBuilder $builder, ?array $filters = null): void
    {
        if (isset($filters['_search']) && $filters['_search']) {
            if (!empty($filters['domain'])) {
                $builder->andWhere($builder->expr()->like('tu.domain', ':domain'))
                    ->setParameter('domain', sprintf('%%%s%%', $filters['domain']));
            }

            if (!empty($filters['key'])) {
                $builder->andWhere($builder->expr()->like('tu.key', ':key'))
                    ->setParameter('key', sprintf('%%%s%%', $filters['key']));
            }
        }
    }

    /**
     * Add conditions according to given filters.
     */
    protected function addTranslationFilter(QueryBuilder $builder, ?array $locales = null, ?array $filters = null): void
    {
        if (null !== $locales) {
            $qb = $this->createQueryBuilder('tu');
            $qb->select('DISTINCT tu.id')
                ->leftJoin('tu.translations', 't')
                ->where($qb->expr()->in('t.locale', $locales));

            foreach ($locales as $locale) {
                if (!empty($filters[$locale])) {
                    $qb->andWhere($qb->expr()->like('t.content', ':content'))
                        ->setParameter('content', sprintf('%%%s%%', $filters[$locale]));

                    $qb->andWhere($qb->expr()->eq('t.locale', ':locale'))
                        ->setParameter('locale', sprintf('%s', $locale));
                }
            }

            $ids = $qb->getQuery()->getResult('SingleColumnArrayHydrator');

            if ((is_countable($ids) ? count($ids) : 0) > 0) {
                $builder->andWhere($builder->expr()->in('tu.id', $ids));
            } else {
                $builder->andWhere($builder->expr()->eq(1, 0));
            }
        }
    }

    /**
     * Load custom hydrator.
     */
    protected function loadCustomHydrator(): void
    {
        $config = $this->getEntityManager()->getConfiguration();
        $config->addCustomHydrationMode('SingleColumnArrayHydrator', SingleColumnArrayHydrator::class);
    }
}
