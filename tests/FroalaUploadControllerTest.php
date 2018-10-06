<?php

namespace Froala\NovaFroalaField\Tests;

use Froala\NovaFroalaField\Models\Attachment;
use Froala\NovaFroalaField\Tests\Fixtures\Article;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Froala\NovaFroalaField\Models\PendingAttachment;

class FroalaUploadControllerTest extends TestCase
{
    protected $file;

    protected $draftId;

    public function setUp()
    {
        parent::setUp();

        Storage::fake(static::DISK);

        $this->draftId = Str::uuid();

        $this->regenerateUpload();
    }

    /** @test */
    public function store_pending_attachment()
    {
        $response = $this->uploadPendingFile();

        $response->assertJson(['link' => Storage::disk(static::DISK)->url($this->file->hashName())]);

        $this->assertDatabaseHas((new PendingAttachment)->getTable(), [
            'draft_id' => $this->draftId,
            'disk' => static::DISK,
            'attachment' => $this->file->hashName(),
        ]);

        // Assert the file was stored...
        Storage::disk(static::DISK)->assertExists($this->file->hashName());

        // Assert a file does not exist...
        Storage::disk(static::DISK)->assertMissing('missing.jpg');
    }

    /** @test */
    public function store_attachment()
    {
        $this->uploadPendingFile();

        $response = $this->storeArticle();

        $response->assertJson([
            'title' => 'Some title',
            'content' => 'Some content',
        ]);

        $this->assertDatabaseHas((new Attachment)->getTable(), [
            'disk' => static::DISK,
            'attachment' => $this->file->hashName(),
            'url' => Storage::disk(static::DISK)->url($this->file->hashName()),
            'attachable_id' => $response->json('id'),
            'attachable_type' => Article::class,
        ]);
    }

    /** @test */
    public function detach_attachment()
    {

        $src = $this->uploadPendingFile()->json('link');

        $this->storeArticle();

        Storage::disk(static::DISK)->assertExists($this->file->hashName());

        $this->json('DELETE', 'nova-vendor/froala-field/articles/attachments/content', [
            'src' => $src,
        ]);

        Storage::disk(static::DISK)->assertMissing($this->file->hashName());
    }

    /** @test */
    public function discard_pending_attachments()
    {
        $fileNames = [];

        for ($i = 0; $i <= 3; ++$i) {
            $this->uploadPendingFile();

            $fileNames[] = $this->file->hashName();

            $this->regenerateUpload();
        }

        foreach ($fileNames as $fileName) {
            Storage::disk(static::DISK)->assertExists($fileName);
        }

        $this->json('DELETE', 'nova-vendor/froala-field/articles/attachments/content/'.$this->draftId);

        foreach ($fileNames as $fileName) {
            Storage::disk(static::DISK)->assertMissing($fileName);
        }
    }

    protected function regenerateUpload()
    {
        $this->file = UploadedFile::fake()->image('picture'.random_int(1,100).'.jpg');
    }

    /**
     * @return \Illuminate\Foundation\Testing\TestResponse
     */
    protected function uploadPendingFile(): \Illuminate\Foundation\Testing\TestResponse
    {
        return $this->json('POST', 'nova-vendor/froala-field/articles/attachments/content', [
            'draftId' => $this->draftId,
            'attachment' => $this->file,
        ]);
    }

    /**
     * @return \Illuminate\Foundation\Testing\TestResponse
     */
    protected function storeArticle(): \Illuminate\Foundation\Testing\TestResponse
    {
        return $this->json('POST', 'nova-api/articles', [
            'title' => 'Some title',
            'content' => 'Some content',
            'contentDraftId' => $this->draftId,
        ]);
    }
}
