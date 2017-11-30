<?php namespace AGCMS\Controller\Admin;

use AGCMS\Entity\Brand;
use AGCMS\ORM;
use AGCMS\Render;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class BrandController extends AbstractAdminController
{
    /**
     * Index page for brands.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function index(Request $request): Response
    {
        $data = $this->basicPageData($request);
        $data['brands'] = ORM::getByQuery(Brand::class, 'SELECT * FROM `maerke` ORDER BY navn');
        $content = Render::render('admin/maerker', $data);

        return new Response($content);
    }

    /**
     * Page for editing or creating a brand.
     *
     * @param Request $request
     * @param int     $id
     *
     * @return Response
     */
    public function editPage(Request $request, int $id): Response
    {
        $data = $this->basicPageData($request);
        $data['brand'] = $id ? ORM::getOne(Brand::class, $id) : null;

        $content = Render::render('admin/updatemaerke', $data);

        return new Response($content);
    }

    /**
     * Create new brand.
     *
     * @param Request $request
     *
     * @throws InvalidInput
     *
     * @return JsonResponse
     */
    public function create(Request $request): JsonResponse
    {
        $name = $request->request->get('name', '');
        $link = $request->request->get('link', '');
        $iconId = $request->request->get('iconId');
        if (!$name) {
            throw new InvalidInput(_('You must enter a name.'));
        }

        $brand = new Brand(['title' => $name, 'link' => $link, 'icon_id' => $iconId]);
        $brand->save();

        return new JsonResponse(['id' => $brand->getId()]);
    }

    /**
     * Update a brand.
     *
     * @param Request $request
     * @param int     $id
     *
     * @throws InvalidInput
     *
     * @return JsonResponse
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $name = $request->request->get('name');
        $link = $request->request->get('link', '');
        $iconId = $request->request->get('iconId');
        if (!$name) {
            throw new InvalidInput(_('You must enter a name.'));
        }

        $brand = ORM::getOne(Brand::class, $id);
        if (!$name) {
            throw new InvalidInput(_('The brand dosen\'t exist.'));
        }

        $icon = null;
        if (null !== $iconId) {
            $icon = ORM::getOne(File::class, $iconId);
        }

        $brand->setIcon($icon)
            ->setLink($link)
            ->setTitle($name)
            ->save();

        return new JsonResponse(['id' => $brand->getId()]);
    }

    /**
     * Delete a brand.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function delete(Request $request, int $id): JsonResponse
    {
        /** @var ?Brand */
        $brand = ORM::getOne(Brand::class, $id);
        if ($brand) {
            $brand->delete();
        }

        return new JsonResponse(['id' => 'maerke' . $id]);
    }
}
