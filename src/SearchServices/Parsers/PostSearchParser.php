<?php
namespace Szurubooru\SearchServices\Parsers;

class PostSearchParser extends AbstractSearchParser
{
	protected function createFilter()
	{
		return new \Szurubooru\SearchServices\Filters\PostFilter;
	}

	protected function decorateFilterFromToken($filter, $token)
	{
		$requirement = new \Szurubooru\SearchServices\Requirements\Requirement();
		$requirement->setType(\Szurubooru\SearchServices\Filters\PostFilter::REQUIREMENT_TAG);
		$requirement->setValue($this->createRequirementValue($token->getValue()));
		$requirement->setNegated($token->isNegated());
		$filter->addRequirement($requirement);
	}

	protected function decorateFilterFromNamedToken($filter, $token)
	{
		if ($token->getKey() === 'id')
			$this->addIdRequirement($filter, $token);

		elseif ($token->getKey() === 'hash')
			$this->addHashRequirement($filter, $token);

		elseif ($token->getKey() === 'date')
			$this->addDateRequirement($filter, $token);

		elseif ($token->getKey() === 'tag_count')
			$this->addTagCountRequirement($filter, $token);

		else
			throw new \BadMethodCallException('Not supported');
	}

	protected function getOrderColumn($token)
	{
		if ($token === 'id')
			return \Szurubooru\SearchServices\Filters\PostFilter::ORDER_ID;

		elseif ($token === 'fav_time')
			return \Szurubooru\SearchServices\Filters\PostFilter::ORDER_FAV_TIME;

		elseif ($token === 'fav_count')
			return \Szurubooru\SearchServices\Filters\PostFilter::ORDER_FAV_COUNT;

		elseif ($token === 'tag_count')
			return \Szurubooru\SearchServices\Filters\PostFilter::ORDER_TAG_COUNT;

		elseif ($token === 'time')
			return \Szurubooru\SearchServices\Filters\PostFilter::ORDER_LAST_EDIT_TIME;

		elseif ($token === 'score')
			return \Szurubooru\SearchServices\Filters\PostFilter::ORDER_SCORE;

		throw new \BadMethodCallException('Not supported');
	}

	private function addIdRequirement($filter, $token)
	{
		$requirement = new \Szurubooru\SearchServices\Requirements\Requirement();
		$requirement->setType(\Szurubooru\SearchServices\Filters\PostFilter::REQUIREMENT_ID);
		$requirement->setValue($this->createRequirementValue($token->getValue(), self::ALLOW_COMPOSITE | self::ALLOW_RANGES));
		$requirement->setNegated($token->isNegated());
		$filter->addRequirement($requirement);
	}

	private function addHashRequirement($filter, $token)
	{
		$requirement = new \Szurubooru\SearchServices\Requirements\Requirement();
		$requirement->setType(\Szurubooru\SearchServices\Filters\PostFilter::REQUIREMENT_HASH);
		$requirement->setValue($this->createRequirementValue($token->getValue(), self::ALLOW_COMPOSITE));
		$filter->addRequirement($requirement);
	}

	private function addDateRequirement($filter, $token)
	{
		if (substr_count($token->getValue(), '..') === 1)
		{
			list ($dateMin, $dateMax) = explode('..', $token->getValue());
			$timeMin = $this->dateToTime($dateMin)[0];
			$timeMax = $this->dateToTime($dateMax)[1];
		}
		else
		{
			$date = $token->getValue();
			list ($timeMin, $timeMax) = $this->dateToTime($date);
		}

		$finalString = '';
		if ($timeMin)
			$finalString .= date('c', $timeMin);
		$finalString .= '..';
		if ($timeMax)
			$finalString .= date('c', $timeMax);

		$requirement = new \Szurubooru\SearchServices\Requirements\Requirement();
		$requirement->setType(\Szurubooru\SearchServices\Filters\PostFilter::REQUIREMENT_DATE);
		$requirement->setValue($this->createRequirementValue($finalString, self::ALLOW_RANGES));
		$requirement->setNegated($token->isNegated());
		$filter->addRequirement($requirement);
	}

	private function addTagCountRequirement($filter, $token)
	{
		$requirement = new \Szurubooru\SearchServices\Requirements\Requirement();
		$requirement->setType(\Szurubooru\SearchServices\Filters\PostFilter::REQUIREMENT_TAG_COUNT);
		$requirement->setValue($this->createRequirementValue($token->getValue(), self::ALLOW_COMPOSITE | self::ALLOW_RANGES));
		$filter->addRequirement($requirement);
	}

	private function dateToTime($value)
	{
		$value = strtolower(trim($value));
		if (!$value)
		{
			return null;
		}
		elseif ($value === 'today')
		{
			$timeMin = mktime(0, 0, 0);
			$timeMax = mktime(24, 0, -1);
		}
		elseif ($value === 'yesterday')
		{
			$timeMin = mktime(-24, 0, 0);
			$timeMax = mktime(0, 0, -1);
		}
		elseif (preg_match('/^(\d{4})$/', $value, $matches))
		{
			$year = intval($matches[1]);
			$timeMin = mktime(0, 0, 0, 1, 1, $year);
			$timeMax = mktime(0, 0, -1, 1, 1, $year + 1);
		}
		elseif (preg_match('/^(\d{4})-(\d{1,2})$/', $value, $matches))
		{
			$year = intval($matches[1]);
			$month = intval($matches[2]);
			$timeMin = mktime(0, 0, 0, $month, 1, $year);
			$timeMax = mktime(0, 0, -1, $month + 1, 1, $year);
		}
		elseif (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $matches))
		{
			$year = intval($matches[1]);
			$month = intval($matches[2]);
			$day = intval($matches[3]);
			$timeMin = mktime(0, 0, 0, $month, $day, $year);
			$timeMax = mktime(0, 0, -1, $month, $day + 1, $year);
		}
		else
			throw new \Exception('Invalid date format: ' . $value);

		return [$timeMin, $timeMax];
	}
}
