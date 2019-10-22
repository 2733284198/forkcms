<?php

namespace Backend\Modules\Pages\Domain\Page;

use Backend\Modules\Pages\Domain\ModuleExtra\ModuleExtra;
use Backend\Modules\Pages\Domain\PageBlock\PageBlock;
use Common\Doctrine\Entity\Meta;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;

/**
 * @method Page|null find($id, $lockMode = null, $lockVersion = null)
 * @method Page|null findOneBy(array $criteria, array $orderBy = null)
 * @method Page[]    findAll()
 * @method Page[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Page::class);
    }

    public function add(Page $page): void
    {
        // We don't flush here, see http://disq.us/p/okjc6b
        $this->getEntityManager()->persist($page);
    }

    public function save(Page $page): void
    {
        $this->getEntityManager()->flush($page);
    }

    public function deleteByRevisionIds(array $ids): void
    {
        $this
            ->createQueryBuilder('p')
            ->delete()
            ->where('p.revisionId IN :ids')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }

    public function get(int $id, int $revisionId = null, string $language = null)
    {
        $qb = $this->buildGetQuery($id, $revisionId, $language);

        // @todo This wil not be necessary when we can return the entities instead of arrays
        $results = $qb->getQuery()->getScalarResult();
        foreach ($results as &$result) {
            $result = $this->processFields($result);
        }

        return $results;
    }

    public function getOne(int $id, int $revisionId = null, string $language = null): ?array
    {
        $qb = $this->buildGetQuery($id, $revisionId, $language);
        $qb->setMaxResults(1);

        // @todo This wil not be necessary when we can return the entities instead of arrays
        $results = $qb->getQuery()->getScalarResult();

        if (count($results) === 0) {
            return null;
        }

        return $this->processFields($results[0]);
    }

    private function buildGetQuery(int $pageId, int $revisionId, string $language): QueryBuilder
    {
        $qb = $this->getEntityManager()->createQueryBuilder();

        $qb
            ->select('p')
            ->addSelect('m.id as meta_id')
            ->addSelect('unix_timestamp(p.publishOn) as publish_on')
            ->addSelect('unix_timestamp(p.createdOn) as created_on')
            ->addSelect('unix_timestamp(p.editedOn) as edited_on')
            ->addSelect('ifelse(count(e.id) > 0, 1, 0) as has_extra')
            ->addSelect('group_concat(b.extraId) as extra_ids')
            ->from(Page::class, 'p')
            ->leftJoin(Meta::class, 'm', Join::WITH, 'p.meta = m.id')
            ->leftJoin(PageBlock::class, 'b', Join::WITH, 'b.revisionId = p.revisionId AND b.extraId IS NOT NULL')
            ->leftJoin(ModuleExtra::class, 'e', Join::WITH, 'e.id = b.extraId AND e.type = :type')
            ->where('p.id = :id')
            ->andWhere('p.revisionId = :revisionId')
            ->andWhere('p.language = :language');

        $qb->setParameters(
            [
                'type' => 'block',
                'id' => $pageId,
                'revisionId' => $revisionId,
                'language' => $language,
            ]
        );

        return $qb;
    }

    private function processFields($result): array
    {
        foreach ($result as $key => $value) {
            if (strpos($key, 'p_') === 0) {
                unset($result[$key]);
                $key = substr($key, 2);

                preg_match_all('!([A-Z][A-Z0-9]*(?=$|[A-Z][a-z0-9])|[A-Za-z][a-z0-9]+)!', $key, $matches);
                $ret = $matches[0];
                foreach ($ret as &$match) {
                    $match = ($match === strtoupper($match) ? strtolower($match) : lcfirst($match));
                }
                $key = implode('_', $ret);
            }

            if (is_bool($value)) {
                if ($value === true) {
                    $value = '1';
                } else {
                    $value = '0';
                }
            }

            $result[$key] = $value;
        }

        $result['move_allowed'] = (bool) $result['allow_move'];
        $result['children_allowed'] = (bool) $result['allow_children'];
        $result['delete_allowed'] = (bool) $result['allow_delete'];

        if (Page::isForbiddenToDelete($result['id'])) {
            $result['allow_delete'] = false;
        }

        if (Page::isForbiddenToMove($result['id'])) {
            $result['allow_move'] = false;
        }

        if (Page::isForbiddenToHaveChildren($result['id'])) {
            $result['allow_children'] = false;
        }

        // convert into bools for use in template engine
        $result['edit_allowed'] = (bool) $result['allow_edit'];
        $result['has_extra'] = (bool) $result['has_extra'];

        // unserialize data
        if ($result['data'] !== null) {
            $result['data'] = unserialize($result['data']);
        }

        return $result;
    }
}
