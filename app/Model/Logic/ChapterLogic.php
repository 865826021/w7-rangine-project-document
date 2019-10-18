<?php

/**
 * WeEngine Document System
 *
 * (c) We7Team 2019 <https://www.w7.cc>
 *
 * This is not a free software
 * Using it under the license terms
 * visited https://www.w7.cc for more details
 */

namespace W7\App\Model\Logic;

use W7\App;
use W7\App\Event\ChangeChapterEvent;
use W7\App\Event\ChangeDocumentEvent;
use W7\App\Model\Entity\Document;
use W7\App\Model\Entity\Document\Chapter;
use W7\App\Model\Entity\Document\ChapterContent;
use W7\App\Model\Entity\User;
use W7\Core\Helper\Traiter\InstanceTraiter;

class ChapterLogic extends BaseLogic
{
	use InstanceTraiter;

	public function createChapter($data)
	{
		if ($data['parent_id'] == 0) {
			$data['levels'] = 1;
		} else {
			$parent = Chapter::find($data['parent_id']);
			if (!$parent) {
				throw new \Exception('父章节不存在');
			}
			$this->documentAuth($parent['document_id']);

			$data['levels'] = $parent->levels + 1;
			if ($data['levels'] > 3) {
				throw new \Exception('章节最大层级为３层！');
			}
		}
		$res = $chapter = Chapter::create($data);
		if ($res && icache()->has(DOCUMENT_INFO.$data['document_id'])) {
			icache()->delete(DOCUMENT_INFO.$data['document_id']);
		}
		ChangeChapterEvent::instance()->attach('chapter', $chapter)->dispatch();
		return $chapter;
	}

	public function updateChapter($id, $data)
	{
		$chapter = Chapter::find($id);
		if ($chapter) {
			$this->documentAuth($chapter['document_id']);

			$chapter->name = $data['name'];
			$chapter->sort = $data['sort'];
			$res = $chapter->save();
			if ($res && icache()->has(DOCUMENT_INFO.$chapter['document_id'])) {
				icache()->delete(DOCUMENT_INFO.$chapter['document_id']);
			}
			ChangeChapterEvent::instance()->attach('chapter', $chapter)->dispatch();
			return $chapter;
		}
		throw new \Exception('this chapter is not exist,please refresh the web page!');
	}

	public function publishOrCancel($id, $is_show)
	{
		$document = Chapter::find($id);
		if ($document) {
			$document->is_show = $is_show;
			$document->save();
			ChangeDocumentEvent::instance()->attach('id', $id)->dispatch();

			return true;
		}

		throw new \Exception('该文档不存在');
	}

	public function getCatalog($id)
	{
		$list = Chapter::query()
			->select('id', 'name', 'sort', 'parent_id')
			->where('document_id', $id)
			->orderBy('parent_id', 'asc')
			->orderBy('sort', 'asc')->get()->toArray();

		if (empty($list)) {
			return [];
		}

		$result = [];
		foreach ($list as $id => $item) {
			$result[$item['id']] = $item;
			$result[$item['id']]['children'] = [];
		}

		foreach ($result as $id => $item) {
			if ($item['parent_id'] != 0) {
				$result[$item['parent_id']]['children'][] = &$result[$id];
			}
		}

		return $result;
	}

	public function getById($id, $documentId = 0) {
		$id = intval($id);
		$documentId = intval($documentId);

		$query = Chapter::query()->where('id', $id);
		if (!empty($documentId)) {
			$query = $query->where('document_id', $documentId);
		}

		return $query->first();
	}

	public function getDetail($id, $documentId)
	{
		$chapter = $this->getById($id, $documentId);
		if (!$chapter) {
			throw new \Exception('该章节不存在！');
		}

		$previous = $this->previousChapter($chapter);
		$chapter['previous_chapter_id'] = $previous['id'];
		$chapter['previous_chapter_name'] = $previous['name'];
		$next = $this->nextChapter($chapter);
		$chapter['next_chapter_id'] = $next['id'];
		$chapter['next_chapter_name'] = $next['name'];

		$document = DocumentLogic::instance()->getById($documentId);

		$chapter['creator_id'] = $document->user->id;
		$chapter['username'] = $document->user->username;
		return $chapter;
	}

	public function previousChapter($chapter)
	{
		$parent_id = $chapter['parent_id'];
		$sort = $chapter['sort'];
		$elderBrother = Chapter::where('parent_id', $parent_id)->where('sort', '>', $sort)->orderBy('sort')->first();
		if ($elderBrother) {
			return $elderBrother;
		}
		if ($parent_id) {
			$parent = Chapter::find($parent_id);
			if ($parent) {
				return $parent;
			}
		}
		return ['id' => 0,'name' => ''];
	}

	public function nextChapter($chapter)
	{
		$parent_id = $chapter['parent_id'];
		$sort = $chapter['sort'];
		$id = $chapter['id'];
		$child = Chapter::where('parent_id', $id)->orderBy('sort', 'asc')->first();
		if ($child) {
			return $child;
		}
		$youngerBrother = Chapter::where('parent_id', $parent_id)->where('sort', '<', $sort)->orderBy('sort', 'asc')->first();
		if ($youngerBrother) {
			return $youngerBrother;
		}
		return ['id' => 0,'name' => ''];
	}

	public function searchDocument($id, $keyword)
	{
		$content_ids = ChapterContent::where('content', 'like', '%'.$keyword.'%')->pluck('chapter_id')->toArray();
		$document_ids = Chapter::where('name', 'like', '%'.$keyword.'%')->where('document_id', $id)->pluck('id')->toArray();
		$document_ids = array_merge($content_ids, $document_ids);
		$documents = Chapter::whereIn('id', $document_ids)->where('document_id', $id)->get()->toArray();
		foreach ($documents as &$document) {
			$document['content'] = ChapterContent::find($document['id'])->content ?? '';
			if ($document['content']) {
				$document['content'] = mb_substr($document['content'], 0, 264, 'utf-8');
			}
			$document['layout'] = ChapterContent::find($document['id'])->layout ?? '';
			$document['path'] = $this->getPath($document['parent_id']);
		}

		$documentinfo = Document::where('id', $id)->first();
		if ($documentinfo && $documentinfo['creator_id']) {
			$userinfo = User::where('id', $documentinfo['creator_id'])->first();
			if ($userinfo) {
				foreach ($documents as $key => &$val) {
					$val['creator_id'] = $userinfo['id'];
					$val['username'] = $userinfo['username'];
				}
			}
		}

		return $documents;
	}

	public function getPath($parent_id)
	{
		$path = $parent_id;
		while ($parent_id != 0) {
			$temporary = Chapter::find($parent_id)->first();
			if ($temporary) {
				$parent_id = $temporary->parent_id;
				$path = $parent_id.'/'.$path;
			} else {
				throw new \Exception('路径信息缺失!');
			}
		}
		return $path;
	}

	public function deleteChapter($id)
	{
		if (Chapter::where('parent_id', $id)->count() > 0) {
			throw new \Exception('该章节下有子章节，不可删除！');
		}
		$chapter = Chapter::find($id);
		if ($chapter) {
			$this->documentAuth($chapter['document_id']);
			idb()->beginTransaction();
			$resChapter = $chapter->delete();
			ChapterContent::destroy($id);
			ChangeChapterEvent::instance()->attach('chapter', $chapter)->dispatch();
			if ($resChapter) {
				if ($resChapter && icache()->has(DOCUMENT_INFO.$chapter['document_id'])) {
					icache()->delete(DOCUMENT_INFO.$chapter['document_id']);
				}
				idb()->commit();
				return $chapter;
			} else {
				idb()->rollBack();
				return false;
			}
		}
		throw new \Exception('该章节不存在，请刷新页面');
	}

	public function saveContent($id, $content, $layout)
	{
		$documentInfo = Chapter::find($id);
		if (!$documentInfo['document_id']) {
			throw new \Exception('该章节不存在，请刷新页面');
		}
		$this->documentAuth($documentInfo['document_id']);
		$chapterContent = ChapterContent::find($id);
		if ($chapterContent) {
			$chapterContent->content = $content;
			$chapterContent->layout = $layout;
			$res = $chapterContent->save();
			if ($res) {
				$documentInfo->updated_at = time();
				$documentInfo->save();
			}
		} else {
			$chapterContent = ChapterContent::create(['chapter_id' => $id,'content' => $content,'layout' => $layout]);
			if (!$chapterContent) {
				return false;
			}
		}
		$documents = Document::find($documentInfo['document_id']);
		$username = User::where('id', $documents['creator_id'])->value('username');
		$chapterContent['created_at'] = $documentInfo['created_at'];
		$chapterContent['updated_at'] = $documentInfo['updated_at'];
		$chapterContent['username'] = $username;
		return $chapterContent;
	}

	public function getContent($id)
	{
		$documentinfo = Chapter::where('id', $id)->first();
		if (!$documentinfo || !$documentinfo['document_id']) {
			throw new \Exception('该章节不存在，请刷新页面');
		}
		$this->documentAuth($documentinfo['document_id']);
		$chapter = ChapterContent::find($id);
		if (!$chapter) {
			return $chapter;
		}
		$document = Document::where('id', $documentinfo['document_id'])->first();
		if ($document && $document['creator_id']) {
			$userinfo = User::where('id', $document['creator_id'])->first();
			if ($userinfo) {
				$chapter['creator_id'] = $userinfo['id'];
				$chapter['username'] = $userinfo['username'];
			}
		}
		$chapter['created_at'] = $documentinfo['created_at'];
		$chapter['updated_at'] = $documentinfo['updated_at'];
		return $chapter;
	}

	public function searchChapter($id, $keywords)
	{
		$chapter = Chapter::select('id', 'parent_id', 'name')->where('document_id', $id)->where('name', 'like', '%'.$keywords.'%')->first();
		if ($chapter) {
			$chapter['content'] = ChapterContent::find($chapter['id'])->content ?? '';
			$chapter['path'] = $this->getPath($chapter['parent_id']);
			return $chapter;
		}
		throw new \Exception('没有匹配到任何章节');
	}

	public function deleteDocument($document_id)
	{
		$chapters = Chapter::where('document_id', $document_id)->get();
		foreach ($chapters as $chapter) {
			ChapterContent::where('chapter_id', $chapter->id)->delete();
			$chapter->delete();
		}
		if ($chapters && icache()->has(DOCUMENT_INFO.$document_id)) {
			icache()->delete(DOCUMENT_INFO.$document_id);
		}
		return true;
	}

	public function documentAuth($documentId)
	{
		$request = App::getApp()->getContext()->getRequest();
		$auth = $request->document_user_auth;
		if ($auth != null && is_array($auth) && APP_AUTH_ALL !== $auth && !in_array($documentId, $auth)) {
			throw new \Exception('无权操作');
		}
	}
}
