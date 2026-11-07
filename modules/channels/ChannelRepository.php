<?php
declare(strict_types=1);
namespace PlusChat\Modules\Channels;
use PDO;
final class ChannelRepository {
 public function __construct(private readonly PDO $pdo) {}
 public function findPageBySlug(string $slug,int $viewerId,int $limit=30): ?array {
  $q=$this->pdo->prepare("SELECT c.id,c.slug,c.title,c.description,c.owner_user_id,c.visibility,c.created_at,c.updated_at,u.display_name owner_name,u.username owner_username,(c.owner_user_id=:owner_id) is_owner,EXISTS(SELECT 1 FROM channel_subscriptions s WHERE s.channel_id=c.id AND s.user_id=:viewer_id) is_subscribed,(SELECT COUNT(*) FROM channel_subscriptions s2 WHERE s2.channel_id=c.id) subscriber_count,(SELECT COUNT(*) FROM channel_posts p2 WHERE p2.channel_id=c.id) post_count FROM channels c JOIN users u ON u.id=c.owner_user_id WHERE c.slug=:slug AND (c.visibility='public' OR c.owner_user_id=:visible_owner) LIMIT 1");
  $q->execute(['owner_id'=>$viewerId,'viewer_id'=>$viewerId,'slug'=>$slug,'visible_owner'=>$viewerId]); $c=$q->fetch(PDO::FETCH_ASSOC); if(!$c)return null;
  foreach(['id','owner_user_id','subscriber_count','post_count'] as $k)$c[$k]=(int)$c[$k];
  foreach(['is_owner','is_subscribed'] as $k)$c[$k]=(bool)$c[$k];
  $p=$this->pdo->prepare("SELECT p.id,p.post_type,p.body,p.created_at,p.author_user_id,u.display_name author_name,u.username author_username FROM channel_posts p JOIN users u ON u.id=p.author_user_id WHERE p.channel_id=:id ORDER BY p.id DESC LIMIT ".max(1,min(50,$limit)));
  $p->execute(['id'=>$c['id']]); $c['posts']=$p->fetchAll(PDO::FETCH_ASSOC);
  foreach($c['posts'] as &$post){$post['id']=(int)$post['id'];$post['author_user_id']=(int)$post['author_user_id'];} unset($post);
  return $c;
 }
}