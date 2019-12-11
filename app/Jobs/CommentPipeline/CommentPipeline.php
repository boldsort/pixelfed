<?php

namespace App\Jobs\CommentPipeline;

use App\{
    Notification,
    Status,
    UserFilter
};
use App\Services\NotificationService;
use DB, Cache, Log;
use Illuminate\Support\Facades\Redis;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CommentPipeline implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $status;
    protected $comment;

    /**
     * Delete the job if its models no longer exist.
     *
     * @var bool
     */
    public $deleteWhenMissingModels = true;
    
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Status $status, Status $comment)
    {
        $this->status = $status;
        $this->comment = $comment;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $status = $this->status;
        $comment = $this->comment;

        $target = $status->profile;
        $actor = $comment->profile;

        if ($actor->id === $target->id || $status->comments_disabled == true) {
            return true;
        }
        
        $filtered = UserFilter::whereUserId($target->id)
            ->whereFilterableType('App\Profile')
            ->whereIn('filter_type', ['mute', 'block'])
            ->whereFilterableId($actor->id)
            ->exists();

        if($filtered == true) {
            return;
        }

        DB::transaction(function() use($target, $actor, $comment) {
            $notification = new Notification();
            $notification->profile_id = $target->id;
            $notification->actor_id = $actor->id;
            $notification->action = 'comment';
            $notification->message = $comment->replyToText();
            $notification->rendered = $comment->replyToHtml();
            $notification->item_id = $comment->id;
            $notification->item_type = "App\Status";
            $notification->save();

            NotificationService::setNotification($notification);
            NotificationService::set($notification->profile_id, $notification->id);
        });
    }
}
