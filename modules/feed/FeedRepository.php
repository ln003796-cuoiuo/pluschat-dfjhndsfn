<?php
declare(strict_types=1);
namespace PlusChat\Modules\Feed;
use PDO;
final class FeedRepository {
 public function __construct(private readonly PDO $pdo) {}
 public function subscribedPosts(int $userId,?int $beforeId=null,int $limit=30): array {
  $limit=max(1,min(50,$limit)); $sql="SELECT p.id,p.channel_id,p.post_type,p.body,p.created_at,c.title channel_title,c.slug channel_slug,u.id author_id,u.display_name author_name,u.username author_username FROM channel_posts p JOIN channels c ON c.id=p.channel_id JOIN channel_subscriptions s ON s.channel_id=c.id AND s.user_id=:user_id JOIN users u ON u.id=p.author_user_id"; $params=['user_id'=>$userId];
  if($beforeId!==null&&$beforeId>0){$sql.=' WHERE p.id<:before_id';$params['before_id']=$beforeId;}
  $q=$this->pdo->prepare($sql.' ORDER BY p.id DESC LIMIT '.$limit);$q->execute($params);return $q->fetchAll(PDO::FETCH_ASSOC);
 }
}