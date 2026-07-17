// ========== StarStack IPFS Multi-Source Download System ==========
// clawclaw.tech × StarStack IPFS CDN
// Priority: 1) Server direct  2) StarStack IPFS Gateway  3) Public IPFS Gateway

var STARSTACK_GATEWAY = 'https://starstack.store/ipfs/';
var PUBLIC_GATEWAY = "https://starstack.store/ipfs/";

// APK → IPFS CID mapping (all 15 files uploaded to StarStack 5-node cluster)
var APK_IPFS_MAP = {
  'vipreception-v1.0.0.apk': 'Qmewq25VfzUcjL6utqKDSYDSNPfucrVqxiXYbRQi9ZLXMc',
  'missingperson-v1.0.0.apk': 'QmaJt1eCSEgXPWHyUw79qKaM4AeAQcWnTY8mDfXRg7W636',
  'shopsecurity-v1.0.0.apk': 'QmYzWiHceSQ72Y26gYQwhPK7Lk1ejZoKJ8QKEgMC76Aqws',
  'attendance-clock-v1.0.0.apk': 'QmfWLVbqomrKgtZmecWivH9FTcdo2hKDBfE3WK9ysdQPBs',
  'visitor-count-v2.0.0.apk': 'QmNo8S4HCCoyWWrWxw6bPjdEEq33xbo4U6pmnSJvGFwrXn',
  'golf-ranger-v1.0.0.apk': 'QmXB25vFviJYz8QZ7S2f6m7kELYHNBYfiD9MC7Xh9ZCi2z',
  'falldetect-guardian-v1.0.0.apk': 'QmYYoadetS66HfQGoPZrA8YYnsQQSwydBAUnGZD1dK4kgg',
  'elderguard-care-v1.0.0.apk': 'Qmb71zZ2t8qXtwXurKeH6s4FMmCRcqw68P4Va3yWx2kpU6',
  'pedestriancapture-v1.0.0.apk': 'Qmbbo5pY6RvcxffNXrR44ESYttWLvNnpeW7AYwyzPCyF8z',
  'phone-nas-v1.0.0.apk': 'QmPCMkjPBvhykK12HRVnXJx9BtJiwJ35rKktuS9bJV2q8b',
  'plate-recognizer-v1.0.0.apk': 'QmS4hPrYaoUENw7SJuV3fm1fEV5vHRtZjH76TGCFYgA1kH',
  'smart-bridge-v2.0.1.apk': 'QmXf1wrxFpE4BhscpjaToGqxZFf13TNJveVyaP9GuqQrNv',
  'dogathome-pro-v2.0.0.apk': 'Qmcw9CSM5kstxn38GurFyAdraZoo3ggLhDdN6bPT1rCnfr',
  'clawclaw-store.apk': 'QmPhWYfaLeXjY3ayjfwFqn3Ah2BZu7FbyDctT7r2EPS2e4',
  'smartbridge-macos.dmg': 'QmdJESrsxpLQyi3qt6KSCxxjPURY3LdTmqNwdCMFyKEhSo'
};

/**
 * Smart download: try server first, fallback to StarStack IPFS, then public gateway.
 * @param {string} apkPath - e.g. "/apk/vipreception-v1.0.0.apk" or just "vipreception-v1.0.0.apk"
 */
function smartDownload(apkPath) {
  // Extract filename from path
  var filename = apkPath.replace(/^.*\/apk\//, '').replace(/^\//, '');
  var cid = APK_IPFS_MAP[filename];

  // Try server direct first (fastest)
  var serverUrl = '/apk/' + filename;

  // Quick HEAD check with timeout
  var xhr = new XMLHttpRequest();
  xhr.open('HEAD', serverUrl, true);
  xhr.timeout = 5000;
  xhr.onload = function() {
    if (xhr.status === 200) {
      // Server OK → direct download
      window.location.href = serverUrl;
    } else {
      // Server fail → IPFS fallback
      ipfsFallback(cid, filename);
    }
  };
  xhr.onerror = function() {
    ipfsFallback(cid, filename);
  };
  xhr.ontimeout = function() {
    ipfsFallback(cid, filename);
  };
  xhr.send();

  // Show status toast
  showDownloadToast(filename, cid);
}

function ipfsFallback(cid, filename) {
  if (cid) {
    // Try StarStack Gateway (5-node cluster)
    var starstackUrl = STARSTACK_GATEWAY + cid;
    var x2 = new XMLHttpRequest();
    x2.open('HEAD', starstackUrl, true);
    x2.timeout = 8000;
    x2.onload = function() {
      if (x2.status === 200) {
        window.location.href = starstackUrl;
      } else {
        // StarStack only - reliable gateway
        window.location.href = PUBLIC_GATEWAY + cid + "?fallback";
      }
    };
    x2.onerror = function() {
      window.location.href = PUBLIC_GATEWAY + cid + "?fallback";
    };
    x2.ontimeout = function() {
      window.location.href = PUBLIC_GATEWAY + cid + "?fallback";
    };
    x2.send();
  } else {
    // No CID → just try server URL directly
    window.location.href = '/apk/' + filename;
  }
}

function showDownloadToast(filename, cid) {
  var toast = document.createElement('div');
  toast.style.cssText = 'position:fixed;bottom:20px;right:20px;background:rgba(20,20,30,0.95);color:#fff;padding:12px 20px;border-radius:12px;font-size:13px;z-index:99999;box-shadow:0 4px 20px rgba(0,0,0,0.4);border:1px solid rgba(255,255,255,0.1);max-width:360px;backdrop-filter:blur(10px)';
  var source = cid ? '🌐 多源下载' : '⬇️ 下载中';
  var ipfsInfo = cid ? '<br><span style="color:#7dd3fc;font-size:11px">IPFS: ' + cid.slice(0,20) + '...</span>' : '';
  toast.innerHTML = '<div style="font-weight:600;margin-bottom:4px">' + source + '</div><span style="color:#94a3b8">' + filename + '</span>' + ipfsInfo + '<br><span style="color:#64748b;font-size:11px">⭐ StarStack 5-Node IPFS Cluster</span>';
  document.body.appendChild(toast);
  setTimeout(function() { toast.style.opacity = '0'; toast.style.transition = 'opacity 0.5s'; }, 4000);
  setTimeout(function() { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 5000);
}

// Auto-upgrade all /apk/ links to smart download
document.addEventListener('DOMContentLoaded', function() {
  var apkLinks = document.querySelectorAll('a[href*="/apk/"]');
  apkLinks.forEach(function(link) {
    var href = link.getAttribute('href');
    // Skip if already handled or is external
    if (link.dataset.smartDl === '1') return;
    link.dataset.smartDl = '1';
    link.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation();
      smartDownload(href);
    });
  });
});
