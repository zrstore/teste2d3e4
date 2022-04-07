(function(window) {
  "use strict";

  window.ls.container.get("view").add({
    selector: "data-analytics-activity",
    controller: function(window, element, appwrite, container) {
      let action = element.getAttribute("data-analytics-event") || "click";
      let activity = element.getAttribute("data-analytics-label") || "None";
      let doNotTrack = window.navigator.doNotTrack;

      if(doNotTrack == '1') {
        return;
      }

      fetch('http://localhost:8080/v1/analytics', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          destination: 'GA',
          event: activity,
          eventData: null,
          eventUrl: window.location.href
        })
      });
      
      element.addEventListener(action, function() {
        let account = container.get('account');
        let email = account?.email || element?.elements['email']?.value || '';

        appwrite.analytics.create(email, 'console', activity, window.location.href)
      });
    }
  });
})(window);
