<?php
declare(strict_types=1);
// Isolated API simulation; no network, downloads, installation or live data.
$GLOBALS['latest_fixture'] = [];
$GLOBALS['latest_urls'] = [];
function portal_release_http_get(string $url, array $headers, int $maxBytes, int $timeoutSeconds = 20): array
{
    $GLOBALS['latest_urls'][] = $url;
    if (str_ends_with($url, '/releases/latest')) return $GLOBALS['latest_fixture']['release'];
    return $GLOBALS['latest_fixture']['commit'];
}
require __DIR__.'/../../includes/github_release_updater.php';
$checks=0;
$assert=static function(bool $condition,string $message)use(&$checks):void { $checks++; if (!$condition) throw new RuntimeException($message); };
$response=static fn(array $body):array => ['ok'=>true,'status'=>200,'body'=>json_encode($body)];
$release=static fn(string $tag,int $id):array => ['draft'=>false,'prerelease'=>false,'tag_name'=>$tag,'id'=>$id,'target_commitish'=>'different-branch'];
$shaA=str_repeat('a',40); $shaB=str_repeat('b',40);
$GLOBALS['latest_fixture']=['release'=>$response($release('0.0.1',123)), 'commit'=>$response(['sha'=>$shaA])];
$metadata=portal_github_release_metadata();
$assert(($metadata['ok'] ?? false) && $metadata['tag']==='0.0.1' && $metadata['commit']===$shaA && $metadata['release_id']===123, 'Use the latest release reported by GitHub, irrespective of version ordering or target branch.');
$assert($GLOBALS['latest_urls']===['https://api.github.com/repos/Tokke2/document/releases/latest','https://api.github.com/repos/Tokke2/document/commits/refs%2Ftags%2F0.0.1'], 'Resolve the latest explicit tag ref, not a same-named branch.');
$review=['tag'=>'0.0.1','release_id'=>123,'commit'=>$shaA,'manifest_sha256'=>str_repeat('0',64),'before'=>[]];
$GLOBALS['latest_fixture']['release']=$response($release('older-but-explicit-latest',124));
$GLOBALS['latest_fixture']['commit']=$response(['sha'=>$shaB]);
$assert((portal_github_release_install_approved('/nonexistent',$review)['code'] ?? '')==='release_changed_since_approval','A change of latest release aborts instead of installing a stale choice.');
$GLOBALS['latest_fixture']['release']=$response($release('0.0.1',123));
$assert((portal_github_release_install_approved('/nonexistent',$review)['code'] ?? '')==='release_changed_since_approval','A moved tag is detected even with the same version and release ID.');
$GLOBALS['latest_fixture']['release']=$response($release('0.0.1',124));
$GLOBALS['latest_fixture']['commit']=$response(['sha'=>$shaA]);
$assert((portal_github_release_install_approved('/nonexistent',$review)['code'] ?? '')==='release_changed_since_approval','A replaced release ID is detected.');
$GLOBALS['latest_fixture']['release']=$response($release('0.0.1',123));
$review['commit']=$shaA;
$assert((portal_github_release_install_approved('/nonexistent',$review)['code'] ?? '')==='application_root_unavailable','An unchanged latest release reaches the install path.');
$draft=$release('0.0.1',123); $draft['draft']=true; $GLOBALS['latest_fixture']['release']=$response($draft);
$assert((portal_github_release_metadata()['code'] ?? '')==='github_response_invalid','Drafts are never installed.');
$pre=$release('0.0.1',123); $pre['prerelease']=true; $GLOBALS['latest_fixture']['release']=$response($pre);
$assert((portal_github_release_metadata()['code'] ?? '')==='github_response_invalid','Prereleases are never installed.');
$GLOBALS['latest_fixture']['release']=['ok'=>true,'status'=>404,'body'=>'{}'];
$assert((portal_github_release_metadata()['code'] ?? '')==='no_published_release','A missing latest release fails safely.');
echo "PASS: {$checks} explicit latest-release selection and revalidation checks.\n";
