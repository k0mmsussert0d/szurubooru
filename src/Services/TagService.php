<?php
namespace Szurubooru\Services;

class TagService
{
	private $transactionManager;
	private $tagDao;
	private $fileService;
	private $timeService;

	public function __construct(
		\Szurubooru\Dao\TransactionManager $transactionManager,
		\Szurubooru\Dao\TagDao $tagDao,
		\Szurubooru\Services\TimeService $timeService,
		\Szurubooru\Services\FileService $fileService)
	{
		$this->transactionManager = $transactionManager;
		$this->tagDao = $tagDao;
		$this->timeService = $timeService;
		$this->fileService = $fileService;
	}

	public function getFiltered(\Szurubooru\SearchServices\Filters\TagFilter $filter)
	{
		$transactionFunc = function() use ($filter)
		{
			return $this->tagDao->findFiltered($filter);
		};
		return $this->transactionManager->rollback($transactionFunc);
	}

	public function exportJson()
	{
		$tags = [];
		foreach ($this->tagDao->findAll() as $tag)
		{
			$tags[$tag->getName()] = $tag->getUsages();
		}
		$json = json_encode($tags);
		$this->fileService->save('tags.json', $json);
	}

	public function deleteUnusedTags()
	{
		$transactionFunc = function()
		{
			$this->tagDao->deleteUnused();
		};
		$this->transactionManager->commit($transactionFunc);
	}

	public function createTags(array $tags)
	{
		$transactionFunc = function() use ($tags)
		{
			$tagNameGetter = function($tag)
				{
					return $tag->getName();
				};

			$tagNames = array_map($tagNameGetter, $tags);
			$tagNames = array_filter(array_unique($tagNames));

			$tagsNotToCreate = $this->tagDao->findByNames($tagNames);
			$tagNamesNotToCreate = array_map($tagNameGetter, $tagsNotToCreate);
			$tagNamesToCreate = array_udiff($tagNames, $tagNamesNotToCreate, 'strcasecmp');

			$tagsToCreate = [];
			foreach ($tagNamesToCreate as $tagName)
			{
				$tag = new \Szurubooru\Entities\Tag;
				$tag->setName($tagName);
				$tag->setCreationTime($this->timeService->getCurrentTime());
				$tagsToCreate[] = $tag;
			}
			$createdTags = $this->tagDao->batchSave($tagsToCreate);

			$tagsNotToCreate = array_combine($tagNamesNotToCreate, $tagsNotToCreate);
			$createdTags = array_combine($tagNamesToCreate, $createdTags);
			$result = [];
			foreach ($tags as $key => $tag)
			{
				if (isset($tagsNotToCreate[$tag->getName()]))
					$tag = $tagsNotToCreate[$tag->getName()];
				else
					$tag = $createdTags[$tag->getName()];
				$result[$key] = $tag;
			}
			return $result;
		};
		return $this->transactionManager->commit($transactionFunc);
	}
}