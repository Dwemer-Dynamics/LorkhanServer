// Use the same pie/legend/tooltip presentation as Herika's audit page, from a local pinned library.
(() => {
    const form=document.getElementById('cost-filter-form');
    form?.querySelector('input[value="today"]')?.addEventListener('change',()=>form.requestSubmit());
    const data=document.getElementById('cost-chart-data'),canvas=document.getElementById('costChart');
    if(!data||!canvas||typeof Chart==='undefined')return;
    const {labels,values}=JSON.parse(data.textContent);
    new Chart(canvas.getContext('2d'),{
        type:'pie',
        data:{labels:labels.map((label,index)=>`${label} ($${values[index].toFixed(2)})`),datasets:[{
            label:'Total Cost',data:values,
            backgroundColor:['#bc9d5a','#10b981','#f59e0b','#ef4444','#8b5cf6','#ec4899','#14b8a6','#6366f1','#b4a174','#22c55e'],
            borderColor:'#2a2a2a',borderWidth:2
        }]},
        options:{responsive:true,maintainAspectRatio:true,animation:matchMedia('(prefers-reduced-motion: reduce)').matches?false:undefined,
            plugins:{legend:{position:'bottom',labels:{boxWidth:20,padding:15,color:'#f8f9fa',font:{size:13}}},
                tooltip:{backgroundColor:'#2a2a2a',titleColor:'#bc9d5a',bodyColor:'#f8f9fa',borderColor:'#4a4a4a',borderWidth:1,
                    callbacks:{label:context=>`${context.label}: $${context.parsed.toFixed(2)}`}}
            }
        }
    });
})();
