<?php namespace AGCMS\Controller;

use AGCMS\Entity\Brand;
use AGCMS\Entity\Category;
use AGCMS\Entity\CustomPage;
use AGCMS\Entity\Page;
use AGCMS\Entity\Requirement;
use AGCMS\ORM;
use AGCMS\Interfaces\Renderable;
use AGCMS\Render;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

class Site extends Base
{
    /**
     * View a category
     *
     * @param Request $request
     * @param int     $categoryId
     *
     * @return Response
     */
    public function category(Request $request, int $categoryId): Response
    {
        /** @var Category */
        $category = ORM::getOne(Category::class, $categoryId);
        if ($redirect = $this->checkCategoryUrl($request, $category)) {
            return $redirect;
        }
        assert($category instanceof Category);

        $template = Category::GALLERY === $category->getRenderMode() ? 'tiles' : 'list';

        $renderable = $category;
        $pages = $category->getPages();
        if (1 === count($pages)) {
            $renderable = array_shift($pages);
            $template = 'product';
        }

        $data = [
            'crumbs'     => $category->getBranch(),
            'category'   => $category,
            'renderable' => $renderable,
        ] + $this->basicPageData();
        $content = Render::render($template, $data);

        return new Response($content);
    }

    /**
     * View the frontpage
     *
     * @param Request $request
     *
     * @return Response
     */
    public function frontPage(Request $request): Response
    {
        $data = [
            'renderable' => ORM::getOne(CustomPage::class, 1),
        ] + $this->basicPageData();
        $content = Render::render('index', $data);

        return new Response($content);
    }

    /**
     * View page in the root category
     *
     * @param Request $request
     * @param int     $pageId
     *
     * @return Response
     */
    public function rootPage(Request $request, int $pageId): Response
    {
        return $this->page($request, 0, $pageId);
    }

    /**
     * View a page
     *
     * @param Request $request
     * @param int     $categoryId
     * @param int     $pageId
     *
     * @return Response
     */
    public function page(Request $request, int $categoryId, int $pageId): Response
    {
        /** @var Category */
        $category = ORM::getOne(Category::class, $categoryId);
        /** @var Page */
        $page = ORM::getOne(Page::class, $pageId);

        if ($redirect = $this->checkPageUrl($request, $category, $page)) {
            return $redirect;
        }
        assert($category instanceof Category);
        assert($page instanceof Page);

        $crumbs = $category->getBranch();
        $crumbs[] = $page;

        $hasProductTable = false;
        foreach ($page->getTables() as $table) {
            if ($table->hasPrices() && $table->hasPrices()) {
                $hasProductTable = true;
                break;
            }
        }

        $data = [
            'crumbs'          => $crumbs,
            'category'        => $category,
            'renderable'      => $page,
            'hasProductTable' => $hasProductTable,
        ] + $this->basicPageData();
        $content = Render::render('product', $data);

        return new Response($content);
    }

    /**
     * View a requirement notice
     *
     * @param Request $request
     * @param int     $requirementId
     *
     * @return Response
     */
    public function requirement(Request $request, int $requirementId): Response
    {
        /** @var Requirement */
        $requirement = ORM::getOne(Requirement::class, $requirementId);
        if ($redirect = $this->checkRenderableUrl($request, $requirement)) {
            return $redirect;
        }

        $data = [
            'renderable' => $requirement,
        ] + $this->basicPageData();
        $data['crumbs'][] = $requirement;
        $content = Render::render('requirement', $data);

        return new Response($content);
    }

    /**
     * View a brand
     *
     * @param Request $request
     * @param int     $brandId
     *
     * @return Response
     */
    public function brand(Request $request, int $brandId): Response
    {
        /** @var Brand */
        $brand = ORM::getOne(Brand::class, $brandId);
        if ($redirect = $this->checkRenderableUrl($request, $brand)) {
            return $redirect;
        }

        $data = [
            'brand' => $brand,
            'renderable' => $brand,
        ] + $this->basicPageData();
        $data['crumbs'][] = $brand;
        $content = Render::render('list', $data);

        return new Response($content);
    }

    /**
     * Get the basice render data
     *
     * @return array
     */
    private function basicPageData(): array
    {
        /** @var Category */
        $category = ORM::getOne(Category::class, 0);

        return [
            'menu'           => $category->getVisibleChildren(),
            'infoPage'       => ORM::getOne(CustomPage::class, 2),
            'crumbs'         => [$category],
            'category'       => $category,
            'hasItemsInCart' => !empty($_SESSION['faktura']['quantities']),
        ];
    }

    /**
     * Check that the url for a category is correct
     *
     * Returns a redirect responce if the url is not valid
     *
     * @param Request   $request
     * @param ?Category $category
     *
     * @return ?RedirectResponse
     */
    private function checkCategoryUrl(Request $request, ?Category $category): ?RedirectResponse
    {
        if ($category && !$category->isVisable()) {
            return $this->redirectToSearch($request);
        }

        if ($redirect = $this->checkRenderableUrl($request, $category)) {
            return $redirect;
        }

        return null;
    }

    /**
     * Check that the url for a page is correct
     *
     * Returns a redirect responce if the url is not valid
     *
     * @param Request   $request
     * @param ?Category $category
     * @param ?Page     $page
     *
     * @return ?RedirectResponse
     */
    private function checkPageUrl(Request $request, ?Category $category, ?Page $page): ?RedirectResponse
    {
        if (!$page || $page->isInactive()) {
            if ($category && $category->isVisable()) {
                $status = $page ? Response::HTTP_FOUND : Response::HTTP_MOVED_PERMANENTLY;

                return $this->redirect($request, $category->getCanonicalLink(), $status);
            }

            return $this->redirectToSearch($request);
        }

        if ($page->getCanonicalLink($category) !== urldecode($request->getRequestUri())) {
            return $this->redirect($request, $page->getCanonicalLink($category), Response::HTTP_MOVED_PERMANENTLY);
        }

        return null;
    }

    /**
     * Check that the url for a renderable is correct
     *
     * Returns a redirect responce if the url is not valid
     *
     * @param Request     $request
     * @param ?Renderable $renderable
     *
     * @return ?RedirectResponse
     */
    private function checkRenderableUrl(Request $request, ?Renderable $renderable): ?RedirectResponse
    {
        if (!$renderable) {
            return $this->redirectToSearch($request);
        }

        if ($renderable->getCanonicalLink() !== urldecode($request->getRequestUri())) {
            return $this->redirect($request, $renderable->getCanonicalLink(), Response::HTTP_MOVED_PERMANENTLY);
        }

        return null;
    }
}