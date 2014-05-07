<?php
use \Chibi\Sql as Sql;
use \Chibi\Database as Database;

class PostModel extends AbstractCrudModel
{
	public static function getTableName()
	{
		return 'post';
	}

	public static function convertRow($row)
	{
		$entity = parent::convertRow($row);

		if (isset($row['type']))
			$entity->setType(new PostType($row['type']));

		if (isset($row['safety']))
			$entity->setSafety(new PostSafety($row['safety']));

		return $entity;
	}

	public static function spawn()
	{
		$post = new PostEntity;
		$post->setSafety(new PostSafety(PostSafety::Safe));
		$post->setHidden(false);
		$post->uploadDate = time();
		do
		{
			$post->setName(md5(mt_rand() . uniqid()));
		}
		while (file_exists($post->getFullPath()));
		return $post;
	}

	public static function save($post)
	{
		$post->validate();

		Database::transaction(function() use ($post)
		{
			self::forgeId($post);

			$bindings = [
				'type' => $post->getType()->toInteger(),
				'name' => $post->getName(),
				'orig_name' => $post->origName,
				'file_hash' => $post->fileHash,
				'file_size' => $post->fileSize,
				'mime_type' => $post->mimeType,
				'safety' => $post->getSafety()->toInteger(),
				'hidden' => $post->isHidden(),
				'upload_date' => $post->uploadDate,
				'image_width' => $post->getImageWidth(),
				'image_height' => $post->getImageHeight(),
				'uploader_id' => $post->getUploaderId(),
				'source' => $post->getSource(),
				];

			$stmt = new Sql\UpdateStatement();
			$stmt->setTable('post');

			foreach ($bindings as $key => $value)
				$stmt->setColumn($key, new Sql\Binding($value));

			$stmt->setCriterion(new Sql\EqualsFunctor('id', new Sql\Binding($post->getId())));
			Database::exec($stmt);

			//tags
			$tags = $post->getTags();

			$stmt = new Sql\DeleteStatement();
			$stmt->setTable('post_tag');
			$stmt->setCriterion(new Sql\EqualsFunctor('post_id', new Sql\Binding($post->getId())));
			Database::exec($stmt);

			foreach ($tags as $postTag)
			{
				$stmt = new Sql\InsertStatement();
				$stmt->setTable('post_tag');
				$stmt->setColumn('post_id', new Sql\Binding($post->getId()));
				$stmt->setColumn('tag_id', new Sql\Binding($postTag->getId()));
				Database::exec($stmt);
			}

			//relations
			$relations = $post->getRelations();

			$stmt = new Sql\DeleteStatement();
			$stmt->setTable('crossref');
			$binding = new Sql\Binding($post->getId());
			$stmt->setCriterion((new Sql\DisjunctionFunctor)
				->add(new Sql\EqualsFunctor('post_id', $binding))
				->add(new Sql\EqualsFunctor('post2_id', $binding)));
			Database::exec($stmt);

			foreach ($relations as $relatedPost)
			{
				$stmt = new Sql\InsertStatement();
				$stmt->setTable('crossref');
				$stmt->setColumn('post_id', new Sql\Binding($post->getId()));
				$stmt->setColumn('post2_id', new Sql\Binding($relatedPost->getId()));
				Database::exec($stmt);
			}
		});

		return $post;
	}

	public static function remove($post)
	{
		Database::transaction(function() use ($post)
		{
			$binding = new Sql\Binding($post->getId());

			$stmt = new Sql\DeleteStatement();
			$stmt->setTable('post_score');
			$stmt->setCriterion(new Sql\EqualsFunctor('post_id', $binding));
			Database::exec($stmt);

			$stmt->setTable('post_tag');
			Database::exec($stmt);

			$stmt->setTable('favoritee');
			Database::exec($stmt);

			$stmt->setTable('comment');
			Database::exec($stmt);

			$stmt->setTable('crossref');
			$stmt->setCriterion((new Sql\DisjunctionFunctor)
				->add(new Sql\EqualsFunctor('post_id', $binding))
				->add(new Sql\EqualsFunctor('post_id', $binding)));
			Database::exec($stmt);

			$stmt->setTable('post');
			$stmt->setCriterion(new Sql\EqualsFunctor('id', $binding));
			Database::exec($stmt);
		});
	}




	public static function findByName($key, $throw = true)
	{
		$stmt = new Sql\SelectStatement();
		$stmt->setColumn('*');
		$stmt->setTable('post');
		$stmt->setCriterion(new Sql\EqualsFunctor('name', new Sql\Binding($key)));

		$row = Database::fetchOne($stmt);
		if ($row)
			return self::convertRow($row);

		if ($throw)
			throw new SimpleNotFoundException('Invalid post name "%s"', $key);
		return null;
	}

	public static function findByIdOrName($key, $throw = true)
	{
		if (is_numeric($key))
			$post = self::findById($key, $throw);
		else
			$post = self::findByName($key, $throw);
		return $post;
	}

	public static function findByHash($key, $throw = true)
	{
		$stmt = new Sql\SelectStatement();
		$stmt->setColumn('*');
		$stmt->setTable('post');
		$stmt->setCriterion(new Sql\EqualsFunctor('file_hash', new Sql\Binding($key)));

		$row = Database::fetchOne($stmt);
		if ($row)
			return self::convertRow($row);

		if ($throw)
			throw new SimpleNotFoundException('Invalid post hash "%s"', $hash);
		return null;
	}



	public static function preloadComments($posts)
	{
		if (empty($posts))
			return;

		$postMap = [];
		$tagsMap = [];
		foreach ($posts as $post)
		{
			$postId = $post->getId();
			$postMap[$postId] = $post;
			$commentMap[$postId] = [];
		}
		$postIds = array_unique(array_keys($postMap));

		$stmt = new Sql\SelectStatement();
		$stmt->setTable('comment');
		$stmt->addColumn('comment.*');
		$stmt->addColumn('post_id');
		$stmt->setCriterion(Sql\InFunctor::fromArray('post_id', Sql\Binding::fromArray($postIds)));
		$rows = Database::fetchAll($stmt);

		foreach ($rows as $row)
		{
			if (isset($comments[$row['id']]))
				continue;
			unset($row['post_id']);
			$comment = CommentModel::convertRow($row);
			$comments[$row['id']] = $comment;
		}

		foreach ($rows as $row)
		{
			$postId = $row['post_id'];
			$commentMap[$postId] []= $comments[$row['id']];
		}

		foreach ($commentMap as $postId => $comments)
			$postMap[$postId]->setCache('comments', $comments);
	}

	public static function preloadTags($posts)
	{
		if (empty($posts))
			return;

		$postMap = [];
		$tagsMap = [];
		foreach ($posts as $post)
		{
			$postId = $post->getId();
			$postMap[$postId] = $post;
			$tagsMap[$postId] = [];
		}
		$postIds = array_unique(array_keys($postMap));

		$stmt = new Sql\SelectStatement();
		$stmt->setTable('tag');
		$stmt->addColumn('tag.*');
		$stmt->addColumn('post_id');
		$stmt->addInnerJoin('post_tag', new Sql\EqualsFunctor('post_tag.tag_id', 'tag.id'));
		$stmt->setCriterion(Sql\InFunctor::fromArray('post_id', Sql\Binding::fromArray($postIds)));
		$rows = Database::fetchAll($stmt);

		foreach ($rows as $row)
		{
			if (isset($tags[$row['id']]))
				continue;
			unset($row['post_id']);
			$tag = TagModel::convertRow($row);
			$tags[$row['id']] = $tag;
		}

		foreach ($rows as $row)
		{
			$postId = $row['post_id'];
			$tagsMap[$postId] []= $tags[$row['id']];
		}

		foreach ($tagsMap as $postId => $tags)
			$postMap[$postId]->setCache('tags', $tags);
	}



	public static function validateThumbSize($width, $height)
	{
		$width = $width === null ? getConfig()->browsing->thumbWidth : $width;
		$height = $height === null ? getConfig()->browsing->thumbHeight : $height;
		$width = min(1000, max(1, $width));
		$height = min(1000, max(1, $height));
		return [$width, $height];
	}

	private static function getThumbPathTokenized($text, $name, $width = null, $height = null)
	{
		list ($width, $height) = self::validateThumbSize($width, $height);

		return TextHelper::absolutePath(TextHelper::replaceTokens($text, [
			'fullpath' => getConfig()->main->thumbsPath . DS . $name,
			'width' => $width,
			'height' => $height]));
	}

	public static function getThumbCustomPath($name, $width = null, $height = null)
	{
		return self::getThumbPathTokenized('{fullpath}.custom', $name, $width, $height);
	}

	public static function getThumbDefaultPath($name, $width = null, $height = null)
	{
		return self::getThumbPathTokenized('{fullpath}-{width}x{height}.default', $name, $width, $height);
	}

	public static function getFullPath($name)
	{
		return TextHelper::absolutePath(getConfig()->main->filesPath . DS . $name);
	}
}
