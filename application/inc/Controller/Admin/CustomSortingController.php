<?php namespace AGCMS\Controller\Admin;

use AGCMS\Entity\CustomSorting;
use AGCMS\Exception\InvalidInput;
use AGCMS\ORM;
use AGCMS\Render;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CustomSortingController extends AbstractAdminController
{
    /**
     * Show list of custom sortings.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function index(Request $request): Response
    {
        $data = $this->basicPageData($request);
        $data['lists'] = ORM::getByQuery(CustomSorting::class, 'SELECT * FROM `tablesort`');

        $content = Render::render('admin/listsort', $data);

        return new Response($content);
    }

    /**
     * Edit page for custom sorting.
     *
     * @param Request $request
     * @param int     $id
     *
     * @return Response
     */
    public function listsortEdit(Request $request, int $id = null): Response
    {
        $customSorting = null;
        if (null !== $id) {
            /** @var ?CustomSorting */
            $customSorting = ORM::getOne(CustomSorting::class, $id);
            if (!$customSorting) {
                throw new InvalidInput(_('Custom sorting not found.'), 404);
            }
        }

        $data = [
            'customSorting' => $customSorting,
            'textWidth'     => config('text_width'),
        ] + $this->basicPageData($request);

        $content = Render::render('admin/listsort-edit', $data);

        return new Response($content);
    }

    /**
     * Create new custom sorting.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function create(Request $request): JsonResponse
    {
        $items = $request->get('items', []);
        $title = $request->get('title');
        if (!$title) {
            throw new InvalidInput(_('You must enter a title.'));
        }

        $customSorting = new CustomSorting([
            'title' => $title,
            'items' => $items,
        ]);
        $customSorting->save();

        return new JsonResponse(['id' => $customSorting->getId()]);
    }

    /**
     * Update custom sorting.
     *
     * @param Request $request
     * @param int     $id
     *
     * @return JsonResponse
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $items = $request->get('items', []);
        $title = $request->get('title');
        if (!$title) {
            throw new InvalidInput(_('You must enter a title.'));
        }

        /** @var ?CustomSorting */
        $customSorting = ORM::getOne(CustomSorting::class, $id);
        if (!$customSorting) {
            throw new InvalidInput(_('Custom sorting not found.'), 404);
        }

        $customSorting->setTitle($title)
            ->setItems($items)
            ->save();

        return new JsonResponse([]);
    }
}
