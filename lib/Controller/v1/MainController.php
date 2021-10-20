<?php

namespace OCA\Cookbook\Controller\v1;

use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Controller;
use OCA\Cookbook\Service\RecipeService;
use OCA\Cookbook\Service\DbCacheService;
use OCA\Cookbook\Helper\RestParameterParser;
use OCA\Cookbook\Exception\RecipeExistsException;
use OCP\AppFramework\Http\JSONResponse;

class MainController extends Controller {
	protected $appName;

	/**
	 * @var RecipeService
	 */
	private $service;
	/**
	 * @var DbCacheService
	 */
	private $dbCacheService;
	/**
	 * @var IURLGenerator
	 */
	private $urlGenerator;
	
	/**
	 * @var RestParameterParser
	 */
	private $restParser;

	public function __construct(string $AppName, IRequest $request, RecipeService $recipeService, DbCacheService $dbCacheService, IURLGenerator $urlGenerator, RestParameterParser $restParser) {
		parent::__construct($AppName, $request);

		$this->service = $recipeService;
		$this->urlGenerator = $urlGenerator;
		$this->appName = $AppName;
		$this->dbCacheService = $dbCacheService;
		$this->restParser = $restParser;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function categories() {
		$this->dbCacheService->triggerCheck();
		
		$categories = $this->service->getAllCategoriesInSearchIndex();
		return new DataResponse($categories, 200, ['Content-Type' => 'application/json']);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function keywords() {
		$this->dbCacheService->triggerCheck();
		
		$keywords = $this->service->getAllKeywordsInSearchIndex();
		return new DataResponse($keywords, 200, ['Content-Type' => 'application/json']);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function search($query) {
		$this->dbCacheService->triggerCheck();
		
		$query = urldecode($query);
		try {
			$recipes = $this->service->findRecipesInSearchIndex($query);

			foreach ($recipes as $i => $recipe) {
				$recipes[$i]['imageUrl'] = $this->urlGenerator->linkToRoute(
					'cookbook.recipe_v1.image',
					[
						'id' => $recipe['recipe_id'],
						'size' => 'thumb',
						't' => $this->service->getRecipeMTime($recipe['recipe_id'])
					]
				);
				$recipes[$i]['imagePlaceholderUrl'] = $this->urlGenerator->linkToRoute(
					'cookbook.recipe_v1.image',
					[
						'id' => $recipe['recipe_id'],
						'size' => 'thumb16'
					]
				);
			}

			return new DataResponse($recipes, 200, ['Content-Type' => 'application/json']);
		} catch (\Exception $e) {
			return new DataResponse($e->getMessage(), 500);
		}
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function category($category) {
		$this->dbCacheService->triggerCheck();
		
		$category = urldecode($category);
		try {
			$recipes = $this->service->getRecipesByCategory($category);
			foreach ($recipes as $i => $recipe) {
				$recipes[$i]['imageUrl'] = $this->urlGenerator->linkToRoute(
					'cookbook.recipe_v1.image',
					[
						'id' => $recipe['recipe_id'],
						'size' => 'thumb',
						't' => $this->service->getRecipeMTime($recipe['recipe_id'])
					]
				);
				$recipes[$i]['imagePlaceholderUrl'] = $this->urlGenerator->linkToRoute(
					'cookbook.recipe_v1.image',
					[
						'id' => $recipe['recipe_id'],
						'size' => 'thumb16'
					]
				);
			}

			return new DataResponse($recipes, Http::STATUS_OK, ['Content-Type' => 'application/json']);
		} catch (\Exception $e) {
			return new DataResponse($e->getMessage(), 500);
		}
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function categoryUpdate($category) {
		$this->dbCacheService->triggerCheck();

		$json = $this->restParser->getParameters();
		if (!$json || !isset($json['name']) || !$json['name']) {
			return new DataResponse('New category name not found in data', 400);
		}

		$category = urldecode($category);
		try {
			$recipes = $this->service->getRecipesByCategory($category);
			foreach ($recipes as $recipe) {
				$r = $this->service->getRecipeById($recipe['recipe_id']);
				$r['recipeCategory'] = $json['name'];
				$this->service->addRecipe($r);
			}
			// Update cache
			$this->dbCacheService->updateCache();

			return new DataResponse($json['name'], Http::STATUS_OK, ['Content-Type' => 'application/json']);
		} catch (\Exception $e) {
			return new DataResponse($e->getMessage(), 500);
		}
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function tags($keywords) {
		$this->dbCacheService->triggerCheck();
		$keywords = urldecode($keywords);

		try {
			$recipes = $this->service->getRecipesByKeywords($keywords);
			foreach ($recipes as $i => $recipe) {
				$recipes[$i]['imageUrl'] = $this->urlGenerator->linkToRoute(
					'cookbook.recipe_v1.image',
					[
						'id' => $recipe['recipe_id'],
						'size' => 'thumb',
						't' => $this->service->getRecipeMTime($recipe['recipe_id'])
					]
				);
				$recipes[$i]['imagePlaceholderUrl'] = $this->urlGenerator->linkToRoute(
					'cookbook.recipe_v1.image',
					[
						'id' => $recipe['recipe_id'],
						'size' => 'thumb16'
					]
				);
			}

			return new DataResponse($recipes, Http::STATUS_OK, ['Content-Type' => 'application/json']);
		} catch (\Exception $e) {
			// error_log($e->getMessage());
			return new DataResponse($e->getMessage(), 500);
		}
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function import() {
		$this->dbCacheService->triggerCheck();
		
		$data = $this->restParser->getParameters();
		
		if (!isset($data['url'])) {
			return new DataResponse('Field "url" is required', 400);
		}

		try {
			$recipe_file = $this->service->downloadRecipe($data['url']);
			$recipe_json = $this->service->parseRecipeFile($recipe_file);
			$this->dbCacheService->addRecipe($recipe_file);

			return new DataResponse($recipe_json, Http::STATUS_OK, ['Content-Type' => 'application/json']);
		} catch (RecipeExistsException $ex) {
			$json = [
				'msg' => $ex->getMessage(),
				'line' => $ex->getLine(),
				'file' => $ex->getFile(),
			];
			return new JSONResponse($json, Http::STATUS_CONFLICT);
		} catch (\Exception $e) {
			return new DataResponse($e->getMessage(), 400);
		}
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function new() {
		$this->dbCacheService->triggerCheck();
		
		try {
			$recipe_data = $this->restParser->getParameters();
			$file = $this->service->addRecipe($recipe_data);
			$this->dbCacheService->addRecipe($file);

			return new DataResponse($file->getParent()->getId());
		} catch (\Exception $e) {
			return new DataResponse($e->getMessage(), 500);
		}
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function update($id) {
		$this->dbCacheService->triggerCheck();
		
		try {
			$recipe_data = $this->restParser->getParameters();

			$recipe_data['id'] = $id;

			$file = $this->service->addRecipe($recipe_data);
			$this->dbCacheService->addRecipe($file);
			
			return new DataResponse($id);
		} catch (\Exception $e) {
			return new DataResponse($e->getMessage(), 500);
		}
	}
}
